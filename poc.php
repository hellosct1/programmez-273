<?php
// Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'demo_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('OLLAMA_API', 'http://localhost:11434/api');
define('EMBEDDING_MODEL', 'nomic-embed-text'); // Modèle pour embeddings
define('CHAT_MODEL', 'tinyllama'); // Modèle pour génération de texte
define('VECTOR_DIM', 768); // Dimension des vecteurs nomic-embed-text

// Connexion à MariaDB 11.8+
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    echo "✓ Connexion MariaDB réussie\n";

    // Vérifier la version
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "✓ Version MariaDB: $version\n\n";

} catch (PDOException $e) {
    die("Erreur connexion: " . $e->getMessage() . "\n");
}

// Créer la table avec le type VECTOR natif de MariaDB 11.8
$sql = "CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    texte TEXT NOT NULL,
    embedding VECTOR(" . VECTOR_DIM . ") NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    VECTOR INDEX (embedding)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $pdo->exec($sql);
    echo "✓ Table 'documents' avec colonne VECTOR créée\n\n";
} catch (PDOException $e) {
    die("Erreur création table: " . $e->getMessage() . "\nAssurez-vous d'utiliser MariaDB 11.8+\n");
}

// Fonction pour générer un embedding avec Ollama
function getEmbedding($text, $model = EMBEDDING_MODEL) {
    $data = [
        'model' => $model,
        'prompt' => $text
    ];

    $ch = curl_init(OLLAMA_API . '/embeddings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "✗ Erreur cURL: " . curl_error($ch) . "\n";
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        echo "✗ Erreur HTTP $httpCode: $response\n";
        return null;
    }

    $result = json_decode($response, true);
    return $result['embedding'] ?? null;
}

// Convertir un array en format VECTOR pour MariaDB
function arrayToVector($array) {
    return '[' . implode(',', $array) . ']';
}

// Ajouter un document avec son embedding
function addDocument($pdo, $texte) {
    echo "Ajout: \"$texte\"\n";
    echo "  → Génération de l'embedding...\n";

    $embedding = getEmbedding($texte);
    if (!$embedding) {
        echo "  ✗ Échec de génération\n\n";
        return false;
    }

    $vectorStr = arrayToVector($embedding);

    try {
        $stmt = $pdo->prepare("INSERT INTO documents (texte, embedding) VALUES (?, VEC_FromText(?))");
        $stmt->execute([$texte, $vectorStr]);

        $id = $pdo->lastInsertId();
        echo "  ✓ Document ajouté (ID: $id, Dim: " . count($embedding) . ")\n\n";
        return $id;
    } catch (PDOException $e) {
        echo "  ✗ Erreur SQL: " . $e->getMessage() . "\n\n";
        return false;
    }
}

// Rechercher par similarité avec VECTOR natif
function searchSimilar($pdo, $query, $limit = 3) {
    echo "Recherche pour: \"$query\"\n";

    $queryEmbedding = getEmbedding($query);
    if (!$queryEmbedding) {
        echo "✗ Erreur génération embedding de recherche\n";
        return [];
    }

    $vectorStr = arrayToVector($queryEmbedding);

    try {
        // Utiliser VEC_DISTANCE pour la similarité cosinus
        $sql = "SELECT
                    id,
                    texte,
                    VEC_DISTANCE(embedding, VEC_FromText(?)) as distance
                FROM documents
                ORDER BY distance ASC
                LIMIT ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$vectorStr, $limit]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "✓ Trouvé " . count($results) . " résultats\n\n";
        return $results;

    } catch (PDOException $e) {
        echo "✗ Erreur recherche: " . $e->getMessage() . "\n";
        return [];
    }
}

// Générer une réponse avec TinyLlama en utilisant le contexte
function askWithContext($pdo, $question) {
    echo "=== Question avec RAG ===\n";
    echo "Question: $question\n\n";

    $similarDocs = searchSimilar($pdo, $question, 2);

    if (empty($similarDocs)) {
        echo "Aucun contexte trouvé\n";
        return null;
    }

    $context = "Based on this information:\n";
    foreach ($similarDocs as $i => $doc) {
        $context .= ($i + 1) . ". " . $doc['texte'] . "\n";
    }

    $prompt = $context . "\nQuestion: $question\nProvide a brief answer:";

    echo "Génération de la réponse avec " . CHAT_MODEL . "...\n";

    $data = [
        'model' => CHAT_MODEL,
        'prompt' => $prompt,
        'stream' => false
    ];

    $ch = curl_init(OLLAMA_API . '/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['response'] ?? 'Pas de réponse';
}

// Afficher tous les documents
function listDocuments($pdo) {
    $stmt = $pdo->query("SELECT id, texte, created_at FROM documents ORDER BY created_at DESC");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== Documents dans la base (" . count($docs) . ") ===\n";
    foreach ($docs as $doc) {
        echo "ID {$doc['id']}: {$doc['texte']}\n";
    }
    echo "\n";
}

// ============ EXEMPLE D'UTILISATION ============

echo "=== AJOUT DE DOCUMENTS ===\n\n";

$documents = [
    "PHP est un langage de script côté serveur conçu pour le développement web",
    "MariaDB est une base de données entièrement open source",
    "Les bases de données vectorielles permettent la recherche sémantique à l'aide d'embeddings",
    "Ollama permet d'exécuter localement des modèles linguistiques volumineux",
    "TinyLlama est un modèle linguistique petit mais efficace"
];


foreach ($documents as $doc) {
    addDocument($pdo, $doc);
}

listDocuments($pdo);

echo "=== RECHERCHE VECTORIELLE NATIVE ===\n\n";

$query = "Qu'est-ce qu'un système de base de données ?";
$results = searchSimilar($pdo, $query, 3);

echo "Top 3 résultats par distance:\n";
foreach ($results as $i => $result) {
    $distance = round($result['distance'], 4);
    echo ($i + 1) . ". [Distance: $distance] {$result['texte']}\n";
}
echo "\n\n";

echo "=== RAG: RÉPONSE AVEC CONTEXTE ===\n\n";

$question = "Expliquez ce qu'est MariaDB";
$answer = askWithContext($pdo, $question);
echo "\nRéponse: $answer\n";

echo "\n✓ Script terminé avec succès\n";
?>
