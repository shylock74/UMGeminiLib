<?php
/**
 * testPodcastCore.php
 * Script di test per verificare la generalizzazione di PodcastCore.php
 */

require_once 'PodcastCore.php';

try {
    $domanda = "Quali sono i punti chiave del futuro del coding?";
    $yamlFile = 'testKB.yaml';
    $options = [
        'podcastName' => 'Tech Insight',
        'experts' => 'di Alex Raccuglia',
        'fallbackPrefix' => 'Nel podcast Tech Insight non abbiamo parlato di questo, ma ecco cosa penso: '
    ];

    echo "--- TEST 1: Domanda presente in KB ---\n";
    $result = PodcastCore::elaboraDomanda($domanda, $yamlFile, DEFAULT_MODEL, $options);
    echo "Output:\n" . $result['output'] . "\n\n";

    echo "--- TEST 2: Domanda NON presente in KB (Fallback) ---\n";
    $domandaFallback = "Come si cucina la pasta alla carbonara?";
    $resultFallback = PodcastCore::elaboraDomanda($domandaFallback, $yamlFile, DEFAULT_MODEL, $options);
    echo "Output:\n" . $resultFallback['output'] . "\n\n";

} catch (Exception $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
}
