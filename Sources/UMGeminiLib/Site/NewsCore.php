<?php
/**
 * NewsCore.php
 * Acquisizione, filtraggio ed elaborazione editoriale automatica delle notizie enologiche da Google News.
 * Estrazione automatica immagini OpenGraph/RSS e shortener con tracciamento click.
 */

require_once 'Gemini.php';
require_once 'OpenAI.php';
require_once 'config.php';
require_once 'db.php';

class NewsCore {
    /**
     * Recupera le ultime notizie dal feed RSS di Google News per l'enologia
     * 
     * @param string $query Query di ricerca (default: vino OR enologia OR viticoltura OR sommelier OR cantine)
     * @param int $maxItems Numero massimo di articoli da estrarre
     * @return array Lista di notizie con title, link, pubDate, source, description, image
     */
    public static function fetchGoogleNewsRss($query = 'vino OR enologia OR viticoltura OR sommelier OR cantine', $maxItems = 15) {
        $url = "https://news.google.com/rss/search?q=" . urlencode($query) . "&hl=it&gl=IT&ceid=IT:it";
        
        $rssContent = false;
        if (function_exists('curl_version')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $rssContent = curl_exec($ch);
            curl_close($ch);
        } else {
            $rssContent = @file_get_contents($url);
        }

        if (!$rssContent) {
            throw new Exception("Impossibile scaricare il feed RSS da Google News.");
        }

        $items = [];
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rssContent, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml && isset($xml->channel->item)) {
            $count = 0;
            foreach ($xml->channel->item as $item) {
                if ($count >= $maxItems) break;

                $title = trim((string)$item->title);
                $link = trim((string)$item->link);
                $pubDate = trim((string)$item->pubDate);
                $rawDesc = (string)$item->description;
                $description = trim(strip_tags($rawDesc));
                
                // Estrae eventuale immagine dal tag enclosure o media o dall'HTML della description
                $image = null;
                if (isset($item->enclosure) && isset($item->enclosure['url'])) {
                    $image = (string)$item->enclosure['url'];
                } elseif (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawDesc, $mImg)) {
                    $image = $mImg[1];
                }

                // Estrae la sorgente dal tag <source> se presente o dal titolo (es: "Titolo - Testata")
                $sourceName = "";
                if (isset($item->source)) {
                    $sourceName = trim((string)$item->source);
                }
                if (empty($sourceName) && strpos($title, ' - ') !== false) {
                    $parts = explode(' - ', $title);
                    $sourceName = trim(array_pop($parts));
                    $title = trim(implode(' - ', $parts));
                }

                $items[] = [
                    'title' => $title,
                    'link' => $link,
                    'pubDate' => $pubDate,
                    'source' => $sourceName ?: 'Google News',
                    'description' => $description,
                    'image' => $image
                ];
                $count++;
            }
        } else {
            throw new Exception("Nessun articolo valido trovato nel feed RSS.");
        }

        return $items;
    }

    /**
     * Estrae l'immagine di copertina dall'articolo originale (OpenGraph og:image o twitter:image)
     * 
     * @param string $articleUrl URL dell'articolo
     * @param string|null $rssImage Immagine già estratta dall'RSS
     * @return string|null URL dell'immagine estratta o null se non trovata
     */
    public static function extractArticleImage($articleUrl, $rssImage = null) {
        if (!empty($rssImage) && filter_var($rssImage, FILTER_VALIDATE_URL)) {
            return $rssImage;
        }

        if (empty($articleUrl)) return null;

        if (function_exists('curl_version')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $articleUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
            $html = curl_exec($ch);
            curl_close($ch);

            if ($html) {
                // Ricerca OpenGraph og:image
                if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                    $img = html_entity_decode(trim($m[1]));
                    if (filter_var($img, FILTER_VALIDATE_URL)) return $img;
                }
                if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
                    $img = html_entity_decode(trim($m[1]));
                    if (filter_var($img, FILTER_VALIDATE_URL)) return $img;
                }
                // Ricerca twitter:image
                if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                    $img = html_entity_decode(trim($m[1]));
                    if (filter_var($img, FILTER_VALIDATE_URL)) return $img;
                }
                // Ricerca link image_src
                if (preg_match('/<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
                    $img = html_entity_decode(trim($m[1]));
                    if (filter_var($img, FILTER_VALIDATE_URL)) return $img;
                }
            }
        }

        return null;
    }

    /**
     * Seleziona la notizia del giorno ed elabora il post editoriale con l'IA
     * 
     * @param PDO $pdo Connessione al database
     * @param int $podcastId ID del podcast (default: 1)
     * @param array $options Opzioni di configurazione
     * @param string $model Modello da utilizzare
     * @param callable $onWait Callback di notifica
     * @return array Dati del post editoriale pronti per l'invio
     */
    public static function generaPostNews($pdo, $podcastId = 1, $options = [], $model = DEFAULT_MODEL, $onWait = null) {
        ensureQuizTables($pdo);

        // 1. Carica info del podcast
        $stmt = $pdo->prepare("SELECT * FROM podcasts WHERE id = :id");
        $stmt->execute([':id' => $podcastId]);
        $podcast = $stmt->fetch();
        
        $podcastName = $podcast['podcast_name'] ?? "Il vino lo porto io";
        $experts = $podcast['experts'] ?? "del Sommelier Marco Barbetti e dello Chef/sommelier Gabriele Palermo";
        $emoji = $podcast['emoji'] ?? "🍷";
        $fallbackPhoto = !empty($podcast['final_photo']) ? $podcast['final_photo'] : 'VinoBot.jpg';

        // 2. Recupera articoli già inviati in passato per evitare duplicati
        $stmtSent = $pdo->prepare("SELECT article_url, article_title FROM sent_news WHERE podcast_id = :podcast_id ORDER BY id DESC LIMIT 100");
        $stmtSent->execute([':podcast_id' => $podcastId]);
        $sentHistory = $stmtSent->fetchAll();
        $sentUrls = array_column($sentHistory, 'article_url');
        $sentTitles = array_column($sentHistory, 'article_title');

        // 3. Scarica le notizie fresche dal feed RSS
        $articles = self::fetchGoogleNewsRss('vino OR enologia OR viticoltura OR sommelier OR cantine', 20);

        // Filtra escludendo le già inviate
        $freshArticles = [];
        foreach ($articles as $art) {
            $isAlreadySent = false;
            foreach ($sentUrls as $su) {
                if ($su === $art['link']) {
                    $isAlreadySent = true;
                    break;
                }
            }
            if (!$isAlreadySent) {
                foreach ($sentTitles as $st) {
                    if (similar_text(mb_strtolower($st), mb_strtolower($art['title']), $percent) && $percent > 85) {
                        $isAlreadySent = true;
                        break;
                    }
                }
            }
            if (!$isAlreadySent) {
                $freshArticles[] = $art;
            }
        }

        // Se tutti gli articoli sono già stati inviati, prendi i primi più recenti
        if (empty($freshArticles)) {
            $freshArticles = array_slice($articles, 0, 5);
        }

        // 4. Prepara il payload con la rassegna di notizie
        $articlesPayload = "";
        foreach ($freshArticles as $idx => $art) {
            $num = $idx + 1;
            $articlesPayload .= "### Notizia #{$num}:\n";
            $articlesPayload .= "- **Titolo:** {$art['title']}\n";
            $articlesPayload .= "- **Fonte:** {$art['source']}\n";
            $articlesPayload .= "- **Data:** {$art['pubDate']}\n";
            $articlesPayload .= "- **Link:** {$art['link']}\n";
            if (!empty($art['description'])) {
                $articlesPayload .= "- **Estratto:** {$art['description']}\n";
            }
            $articlesPayload .= "\n";
        }

        // 5. Costruzione del Prompt Editoriale
        $prompt = <<<EOD
Sei l'esperto e sommelier del podcast "$podcastName" ($experts).
Ogni giorno selezioni la notizia più interessante, curiosa o impattante dal mondo del vino e la commenti per la community Telegram del podcast con passione, competenza tecnica e un tono caldo e coinvolgente in prima persona.

Ecco l'elenco delle notizie di oggi:

$articlesPayload

## **IL TUO COMPITO:**
1. Scegli UNA sola notizia tra quelle fornite (quella più rilevante, formativa o curiosa per appassionati di vino ed enologia).
2. Scrivi un post editoriale completo per Telegram in prima persona ("Il nostro commento", "Da sommelier vi dico...").
3. Il post deve includere:
   - Un titolo coinvolgente con emoji: "$emoji **RASSEGNA DEL SOMMELIER: [Titolo sintetico ed efficace]**"
   - La sintesi chiara della notizia (cosa è successo o quale novità è emersa).
   - Il tuo commento editoriale esperto (analisi tecnica, impatto sul calice o sui consumatori, o parallelismo con gli abbinamenti del podcast).
   - Conclusione cordiale.
   - In fondo la citazione della fonte con link cliccabile: "📰 _Fonte originale: [Nome Testata](URL_ORIGINALE)_"
4. Restituisci ESCLUSIVAMENTE un oggetto JSON valido con il seguente schema:

{
  "article_title": "Titolo originale dell'articolo scelto",
  "source_name": "Nome della testata o fonte",
  "source_url": "URL esatto dell'articolo scelto",
  "editorial_post": "Testo completo e formattato del post per Telegram (in Markdown)",
  "short_summary": "Una riga di sintesi della notizia"
}
EOD;

        $actualModel = "";
        $usage = null;
        $rawOutput = "";

        try {
            $gemini = new Gemini($model);
            $rawOutput = $gemini->generateText($prompt, $onWait);
            $actualModel = $model;
            $usage = $gemini->getLastUsage();
        } catch (Exception $e) {
            try {
                $openai = new OpenAI(DEFAULT_OPENAI_MODEL);
                $rawOutput = $openai->generateText($prompt, $onWait);
                $actualModel = DEFAULT_OPENAI_MODEL;
                $usage = $openai->getLastUsage();
            } catch (Exception $e2) {
                throw new Exception("Errore generazione news AI: " . $e->getMessage() . " (OpenAI fallback: " . $e2->getMessage() . ")");
            }
        }

        // 6. Pulizia e validazione del JSON
        $newsData = self::parseAndValidateJson($rawOutput, $freshArticles);
        
        // 7. Estrazione dell'immagine dell'articolo originale
        $rssImg = null;
        foreach ($freshArticles as $fa) {
            if ($fa['link'] === $newsData['source_url'] && !empty($fa['image'])) {
                $rssImg = $fa['image'];
                break;
            }
        }
        $extractedImage = self::extractArticleImage($newsData['source_url'], $rssImg);
        $finalImage = $extractedImage ?: $fallbackPhoto;

        // 8. Generazione link corto tracciato per la fonte
        $shortTrackedUrl = createTrackedLink($pdo, $podcastId, $newsData['source_url'], $newsData['article_title']);
        
        // Sostituisce l'URL lungo con l'URL corto tracciato all'interno del post
        if (!empty($shortTrackedUrl) && $shortTrackedUrl !== $newsData['source_url']) {
            $newsData['editorial_post'] = str_replace($newsData['source_url'], $shortTrackedUrl, $newsData['editorial_post']);
        }

        $newsData['short_url'] = $shortTrackedUrl;
        $newsData['image_url'] = $finalImage;
        $newsData['is_custom_image'] = ($extractedImage !== null);
        $newsData['model'] = $actualModel;
        $newsData['usage'] = $usage;

        return $newsData;
    }

    /**
     * Estrae e valida il JSON prodotto dall'IA
     */
    private static function parseAndValidateJson($rawOutput, $fallbackArticles = []) {
        $clean = trim($rawOutput);
        
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        $decoded = json_decode($clean, true);

        if (!$decoded || !is_array($decoded)) {
            if (preg_match('/\{[\s\S]*\}/', $clean, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (!$decoded || empty($decoded['editorial_post'])) {
            $fallback = !empty($fallbackArticles) ? $fallbackArticles[0] : [
                'title' => 'Notizia Enologica del Giorno',
                'source' => 'Google News',
                'link' => 'https://news.google.com'
            ];

            return [
                'article_title' => $fallback['title'],
                'source_name' => $fallback['source'],
                'source_url' => $fallback['link'],
                'editorial_post' => "🍷 *RASSEGNA DEL SOMMELIER: {$fallback['title']}*\n\n" . $clean . "\n\n📰 _Fonte: [{$fallback['source']}]({$fallback['link']})_",
                'short_summary' => $fallback['title']
            ];
        }

        $articleTitle = trim((string)($decoded['article_title'] ?? ''));
        $sourceName = trim((string)($decoded['source_name'] ?? 'Google News'));
        $sourceUrl = trim((string)($decoded['source_url'] ?? ''));
        $editorialPost = trim((string)$decoded['editorial_post']);
        $shortSummary = trim((string)($decoded['short_summary'] ?? $articleTitle));

        if (empty($sourceUrl) || strpos($sourceUrl, 'http') !== 0) {
            foreach ($fallbackArticles as $fa) {
                if (similar_text(mb_strtolower($fa['title']), mb_strtolower($articleTitle), $p) && $p > 60) {
                    $sourceUrl = $fa['link'];
                    $sourceName = $fa['source'];
                    break;
                }
            }
            if (empty($sourceUrl) && !empty($fallbackArticles)) {
                $sourceUrl = $fallbackArticles[0]['link'];
                $sourceName = $fallbackArticles[0]['source'];
            }
        }

        return [
            'article_title' => $articleTitle,
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
            'editorial_post' => $editorialPost,
            'short_summary' => $shortSummary
        ];
    }
}
