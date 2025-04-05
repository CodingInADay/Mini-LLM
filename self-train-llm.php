<?php
header('Content-Type: text/html; charset=UTF-8');

// ثابت‌ها
define('DEFAULT_USER', 'admin');
define('DEFAULT_PASS', 'admin');

// اتصال به دیتابیس
$db = new SQLite3('faradan.sqlite');

// ساخت دیتابیس
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password TEXT NOT NULL)");
$db->exec("CREATE TABLE IF NOT EXISTS pages (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL UNIQUE, title TEXT, content TEXT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS knowledge (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, sentence TEXT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(page_id) REFERENCES pages(id))");
$db->exec("CREATE TABLE IF NOT EXISTS word_index (id INTEGER PRIMARY KEY AUTOINCREMENT, word TEXT NOT NULL, knowledge_id INTEGER, frequency INTEGER DEFAULT 1, FOREIGN KEY(knowledge_id) REFERENCES knowledge(id))");
$db->exec("CREATE TABLE IF NOT EXISTS feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, knowledge_id INTEGER, rating INTEGER, FOREIGN KEY(knowledge_id) REFERENCES knowledge(id))");
$db->exec("CREATE TABLE IF NOT EXISTS corrections (id INTEGER PRIMARY KEY AUTOINCREMENT, query TEXT NOT NULL UNIQUE, answer TEXT NOT NULL)");
$db->exec("CREATE TABLE IF NOT EXISTS ngrams (word1 TEXT, word2 TEXT, word3 TEXT, count INTEGER DEFAULT 1)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_word ON word_index(word)");

// کاربر اولیه
$stmt = $db->prepare("SELECT COUNT(*) FROM users");
$result = $stmt->execute()->fetchArray()[0];
if ($result == 0) {
    $hashedPass = password_hash(DEFAULT_PASS, PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username, password) VALUES ('" . DEFAULT_USER . "', '" . $hashedPass . "')");
}

// مدیریت سشن
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$useWikipedia = isset($_SESSION['useWikipedia']) ? $_SESSION['useWikipedia'] : false;

// زبان
$lang = isset($_GET['lang']) ? $_GET['lang'] : 'fa';

// تابع پردازش محتوا
function fetchWebContent($fileContent, $url, $db) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM pages WHERE url = :url");
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    if ($stmt->execute()->fetchArray()[0] > 0) return 'duplicate';

    $content = $fileContent;
    $title = 'Uploaded File';
    $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\n\r\t]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', trim($text));

    $stmt = $db->prepare("INSERT INTO pages (url, title, content) VALUES (:url, :title, :content)");
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':content', $text, SQLITE3_TEXT);
    $stmt->execute();

    $pageId = $db->lastInsertRowID();
    $sentences = preg_split('/[.!?]+/', $text);
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (strlen($sentence) > 10) {
            $stmt = $db->prepare("INSERT INTO knowledge (page_id, sentence) VALUES (:page_id, :sentence)");
            $stmt->bindValue(':page_id', $pageId, SQLITE3_INTEGER);
            $stmt->bindValue(':sentence', $sentence, SQLITE3_TEXT);
            $stmt->execute();

            $knowledgeId = $db->lastInsertRowID();
            $words = preg_split('/\s+/', $sentence);
            foreach ($words as $i => $word) {
                $word = trim($word, ".,!?");
                if (strlen($word) > 2) {
                    $stmt = $db->prepare("INSERT OR IGNORE INTO word_index (word, knowledge_id) VALUES (:word, :knowledge_id)");
                    $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                    $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt = $db->prepare("UPDATE word_index SET frequency = frequency + 1 WHERE word = :word AND knowledge_id = :knowledge_id");
                    $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                    $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                    $stmt->execute();
                    if (isset($words[$i + 1]) && isset($words[$i + 2])) {
                        $word2 = trim($words[$i + 1], ".,!?");
                        $word3 = trim($words[$i + 2], ".,!?");
                        if (strlen($word2) > 2 && strlen($word3) > 2) {
                            $stmt = $db->prepare("INSERT OR IGNORE INTO ngrams (word1, word2, word3) VALUES (:word1, :word2, :word3)");
                            $stmt->bindValue(':word1', $word, SQLITE3_TEXT);
                            $stmt->bindValue(':word2', $word2, SQLITE3_TEXT);
                            $stmt->bindValue(':word3', $word3, SQLITE3_TEXT);
                            $stmt->execute();
                            $stmt = $db->prepare("UPDATE ngrams SET count = count + 1 WHERE word1 = :word1 AND word2 = :word2 AND word3 = :word3");
                            $stmt->bindValue(':word1', $word, SQLITE3_TEXT);
                            $stmt->bindValue(':word2', $word2, SQLITE3_TEXT);
                            $stmt->bindValue(':word3', $word3, SQLITE3_TEXT);
                            $stmt->execute();
                        }
                    }
                }
            }
        }
    }
    return true;
}

// تابع گرفتن دیتا از ویکی‌پدیا
function getWikipediaData($query, $lang) {
    $url = "https://$lang.wikipedia.org/w/api.php?action=query&prop=extracts&exintro&explaintext&titles=" . urlencode($query) . "&format=json";
    $response = @file_get_contents($url);
    if ($response) {
        $data = json_decode($response, true);
        $pages = $data['query']['pages'] ?? [];
        foreach ($pages as $page) {
            if (isset($page['extract'])) {
                return substr($page['extract'], 0, );
            }
        }
    }
    return null;
}

// تابع تولید متن
function generateText($query, $db, $lang) {
    $tokenCount = $db->querySingle("SELECT COUNT(DISTINCT word) FROM word_index");
    if ($tokenCount < 10) return null;

    $words = preg_split('/\s+/', $query);
    $startWord = null;
    $maxFreq = 0;
    foreach ($words as $word) {
        $word = trim($word, ".,!?");
        if (strlen($word) > 2) {
            $stmt = $db->prepare("SELECT SUM(frequency) as freq FROM word_index WHERE word = :word");
            $stmt->bindValue(':word', $word, SQLITE3_TEXT);
            $freq = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['freq'] ?? 0;
            if ($freq > $maxFreq) {
                $maxFreq = $freq;
                $startWord = $word;
            }
        }
    }
    if (!$startWord) $startWord = $words[0] ?? '';

    $sentence = [$startWord];
    $currentWord1 = $startWord;
    $currentWord2 = null;

    for ($i = 0; $i < 9; $i++) {
        if (!$currentWord2) {
            $stmt = $db->prepare("SELECT word2 FROM ngrams WHERE word1 = :word1 ORDER BY count DESC, RANDOM() LIMIT 1");
            $stmt->bindValue(':word1', $currentWord1, SQLITE3_TEXT);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($result) {
                $currentWord2 = $result['word2'];
                $sentence[] = $currentWord2;
            } else {
                break;
            }
        } else {
            $stmt = $db->prepare("SELECT word3 FROM ngrams WHERE word1 = :word1 AND word2 = :word2 ORDER BY count DESC, RANDOM() LIMIT 1");
            $stmt->bindValue(':word1', $currentWord1, SQLITE3_TEXT);
            $stmt->bindValue(':word2', $currentWord2, SQLITE3_TEXT);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($result) {
                $nextWord = $result['word3'];
                $sentence[] = $nextWord;
                $currentWord1 = $currentWord2;
                $currentWord2 = $nextWord;
            } else {
                break;
            }
        }
    }

    $isFarsiQuery = preg_match('/[\x{0600}-\x{06FF}]/u', $query);
    $finalSentence = [];
    foreach ($sentence as $word) {
        $isFarsiWord = preg_match('/[\x{0600}-\x{06FF}]/u', $word);
        if (($isFarsiQuery && $isFarsiWord) || (!$isFarsiQuery && !$isFarsiWord)) {
            $finalSentence[] = $word;
        }
    }

    return count($finalSentence) > 1 ? implode(' ', $finalSentence) : null;
}

// تابع جستجوی پاسخ
function getAnswer($query, $db, $lang, $useWikipedia, $isLoggedIn, &$moreAvailable = false, &$knowledgeId = null, $usedIds = []) {
    $query = trim($query);
    $stmt = $db->prepare("SELECT answer FROM corrections WHERE query = :query");
    $stmt->bindValue(':query', $query, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($result) {
        $moreAvailable = $db->querySingle("SELECT COUNT(*) FROM knowledge WHERE sentence LIKE '%" . $db->escapeString($query) . "%'") > 0;
        return $result['answer'] . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
    }

    $words = preg_split('/\s+/', $query);
    $stopWords = $lang === 'fa' ? ['کجاست', 'چیست', 'چه', 'کیست', 'است', 'در', 'با', 'به'] : ['where', 'is', 'what', 'who', 'a', 'an', 'the', 'in', 'on', 'at'];
    $keywords = array_filter($words, fn($word) => !in_array($word, $stopWords));

    if (empty($keywords)) return $lang === 'fa' ? 'دانشم کافی نیست!' : 'My knowledge is not enough!';

    $conditions = array_map(fn($keyword) => "word LIKE '%" . $db->escapeString($keyword) . "%'", $keywords);
    $whereClause = implode(' OR ', $conditions);

    $sql = "SELECT DISTINCT knowledge_id FROM word_index WHERE " . $whereClause;
    $result = $db->query($sql);
    $knowledgeIds = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!in_array($row['knowledge_id'], $usedIds)) {
            $knowledgeIds[] = $row['knowledge_id'];
        }
    }

    if (empty($knowledgeIds)) {
        if ($useWikipedia) {
            $wikiAnswer = getWikipediaData($query, $lang);
            if ($wikiAnswer) {
                if ($isLoggedIn) {
                    $url = 'wiki_' . time();
                    fetchWebContent($wikiAnswer, $url, $db);
                }
                $moreAvailable = $db->querySingle("SELECT COUNT(*) FROM knowledge WHERE sentence LIKE '%" . $db->escapeString($query) . "%'") > 0;
                return $wikiAnswer . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
            }
        }
        $generated = generateText($query, $db, $lang);
        $moreAvailable = $useWikipedia || $generated;
        if ($generated) {
            $stmt = $db->prepare("INSERT INTO knowledge (page_id, sentence) VALUES (0, :sentence)");
            $stmt->bindValue(':sentence', $generated, SQLITE3_TEXT);
            $stmt->execute();
            $knowledgeId = $db->lastInsertRowID();

            $words = preg_split('/\s+/', $generated);
            foreach ($words as $word) {
                $word = trim($word, ".,!?");
                if (strlen($word) > 2) {
                    $stmt = $db->prepare("INSERT OR IGNORE INTO word_index (word, knowledge_id) VALUES (:word, :knowledge_id)");
                    $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                    $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt = $db->prepare("UPDATE word_index SET frequency = frequency + 1 WHERE word = :word AND knowledge_id = :knowledge_id");
                    $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                    $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                    $stmt->execute();
                }
            }

            $label = ($lang === 'fa' ? ' [جدید]' : ' [New]');
            return $generated . $label . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
        }
        return ($lang === 'fa' ? 'دانشم کافی نیست!' : 'My knowledge is not enough!') . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
    }

    $idList = implode(',', $knowledgeIds);
    $result = $db->query("SELECT k.id, k.sentence, COALESCE(SUM(f.rating), 0) as rating 
                          FROM knowledge k 
                          LEFT JOIN feedback f ON k.id = f.knowledge_id 
                          WHERE k.id IN ($idList) 
                          GROUP BY k.id, k.sentence");
    $sentences = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sentence = $row['sentence'];
        $score = 0;
        $orderScore = 0;
        $pos = -1;

        foreach ($keywords as $keyword) {
            if (stripos($sentence, $keyword) !== false) {
                $score += 2;
                $newPos = stripos($sentence, $keyword);
                if ($pos < $newPos) $orderScore += 0.5;
                $pos = $newPos;
            }
        }

        $sentences[] = [
            'id' => $row['id'],
            'sentence' => $sentence,
            'score' => $score + $orderScore + ($row['rating'] * 2)
        ];
    }

    usort($sentences, fn($a, $b) => $b['score'] <=> $a['score']);

    $moreAvailable = count($sentences) > 1 || $useWikipedia;
    $selected = $sentences[0] ?? null;
    if ($selected) {
        $knowledgeId = $selected['id'];
        $sentence = htmlspecialchars($selected['sentence']);
        return $sentence . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
    }

    $generated = generateText($query, $db, $lang);
    $moreAvailable = $useWikipedia || $generated;
    if ($generated) {
        $stmt = $db->prepare("INSERT INTO knowledge (page_id, sentence) VALUES (0, :sentence)");
        $stmt->bindValue(':sentence', $generated, SQLITE3_TEXT);
        $stmt->execute();
        $knowledgeId = $db->lastInsertRowID();

        $words = preg_split('/\s+/', $generated);
        foreach ($words as $word) {
            $word = trim($word, ".,!?");
            if (strlen($word) > 2) {
                $stmt = $db->prepare("INSERT OR IGNORE INTO word_index (word, knowledge_id) VALUES (:word, :knowledge_id)");
                $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                $stmt->execute();
                $stmt = $db->prepare("UPDATE word_index SET frequency = frequency + 1 WHERE word = :word AND knowledge_id = :knowledge_id");
                $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                $stmt->execute();
            }
        }

        $label = ($lang === 'fa' ? ' [جدید]' : ' [New]');
        return $generated . $label . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
    }
    return ($lang === 'fa' ? 'دانشم کافی نیست!' : 'My knowledge is not enough!') . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : '');
}

// تعداد توکن‌ها
$tokenCount = $db->querySingle("SELECT COUNT(DISTINCT word) FROM word_index");

// پردازش درخواست‌ها
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $stmt = $db->prepare("SELECT password FROM users WHERE username = :username");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($result && password_verify($password, $result['password'])) {
            $_SESSION['loggedin'] = true;
            echo 'success';
        } else {
            echo 'error';
        }
        exit;
    } elseif (isset($_POST['logout'])) {
        session_destroy();
        echo 'success';
        exit;
    } elseif (isset($_POST['changePass'])) {
        $newPass = $_POST['newPass'] ?? '';
        $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
        $db->exec("UPDATE users SET password = '$hashedPass' WHERE username = '" . DEFAULT_USER . "'");
        echo 'success';
        exit;
    } elseif (isset($_POST['toggleWiki'])) {
        $_SESSION['useWikipedia'] = !$_SESSION['useWikipedia'];
        echo $_SESSION['useWikipedia'] ? 'on' : 'off';
        exit;
    } elseif ($isLoggedIn && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $fileContent = file_get_contents($_FILES['file']['tmp_name']);
        $url = 'uploaded_' . time();
        $result = fetchWebContent($fileContent, $url, $db);
        echo $result === true ? 'success' : ($result === 'duplicate' ? 'duplicate' : 'error');
        exit;
    } elseif ($isLoggedIn && isset($_POST['textInput'])) {
        $text = trim($_POST['textInput']);
        if ($text) {
            $url = 'text_' . time();
            $result = fetchWebContent($text, $url, $db);
            echo $result === true ? 'success' : ($result === 'duplicate' ? 'duplicate' : 'error');
        } else {
            echo 'empty';
        }
        exit;
    } elseif (isset($_POST['query'])) {
        $query = trim($_POST['queryInput']);
        $usedIds = json_decode($_POST['usedIds'] ?? '[]', true);
        $moreAvailable = false;
        $knowledgeId = null;
        $answer = getAnswer($query, $db, $lang, $useWikipedia, $isLoggedIn, $moreAvailable, $knowledgeId, $usedIds);
        $usedIds[] = $knowledgeId;
        $response = '<div class="answer">' . $answer . '</div>';
        if ($knowledgeId || stripos($answer, 'دانشم کافی نیست') === false) {
            $response .= '<div class="feedback">' .
                         '<button onclick="submitFeedback(' . ($knowledgeId ?: 0) . ', 1)">✓</button>';
            if ($isLoggedIn) {
                $response .= '<button onclick="showCorrection()">✗</button>' .
                             '<div id="correctionBox" style="display:none; margin-top: 5px;">' .
                             ($lang === 'fa' ? 'جواب درست: ' : 'Correct answer: ') .
                             '<input type="text" id="correctAnswer">' .
                             '<button onclick="submitCorrection()" style="width: 60px;">' . ($lang === 'fa' ? 'ثبت' : 'Save') . '</button></div>';
            }
            $response .= '</div>';
        }
        $response .= '<input type="hidden" id="usedIds" value=\'' . json_encode($usedIds) . '\'>';
        echo $response;
        exit;
    } elseif (isset($_POST['more'])) {
        $query = trim($_POST['queryInput']);
        $usedIds = json_decode($_POST['usedIds'] ?? '[]', true);
        $moreAvailable = false;
        $knowledgeId = null;
        $answer = getAnswer($query, $db, $lang, $useWikipedia, $isLoggedIn, $moreAvailable, $knowledgeId, $usedIds);
        $usedIds[] = $knowledgeId;
        $response = '<div class="answer">' . $answer . '</div>';
        if ($knowledgeId || stripos($answer, 'دانشم کافی نیست') === false) {
            $response .= '<div class="feedback">' .
                         '<button onclick="submitFeedback(' . ($knowledgeId ?: 0) . ', 1)">✓</button>';
            if ($isLoggedIn) {
                $response .= '<button onclick="showCorrection()">✗</button>' .
                             '<div id="correctionBox" style="display:none; margin-top: 5px;">' .
                             ($lang === 'fa' ? 'جواب درست: ' : 'Correct answer: ') .
                             '<input type="text" id="correctAnswer">' .
                             '<button onclick="submitCorrection()" style="width: 60px;">' . ($lang === 'fa' ? 'ثبت' : 'Save') . '</button></div>';
            }
            $response .= '</div>';
        }
        $response .= '<input type="hidden" id="usedIds" value=\'' . json_encode($usedIds) . '\'>';
        echo $response;
        exit;
    } elseif (isset($_POST['feedback'])) {
        $knowledgeId = $_POST['knowledgeId'] ?? 0;
        $rating = $_POST['rating'] ?? 0;
        if ($knowledgeId) {
            $stmt = $db->prepare("INSERT INTO feedback (knowledge_id, rating) VALUES (:knowledge_id, :rating)");
            $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
            $stmt->bindValue(':rating', $rating, SQLITE3_INTEGER);
            $stmt->execute();
        }
        echo 'success';
        exit;
    } elseif ($isLoggedIn && isset($_POST['correction'])) {
        $query = trim($_POST['queryInput']);
        $correctAnswer = trim($_POST['correctAnswer']);
        if ($correctAnswer) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO corrections (query, answer) VALUES (:query, :answer)");
            $stmt->bindValue(':query', $query, SQLITE3_TEXT);
            $stmt->bindValue(':answer', $correctAnswer, SQLITE3_TEXT);
            $stmt->execute();
            $url = 'correction_' . time();
            fetchWebContent($correctAnswer, $url, $db);
        }
        echo 'success';
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'fa' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرادان | Faradan</title>
    <link rel="shortcut icon" href="https://cdn.sstatic.net/Sites/stackoverflow/Img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* متغیرهای اصلی برای تم‌ها */
        :root {
            --dark-bg: #1e2a44;
            --dark-frame: #2c3e50;
            --dark-accent: #8e44ad;
            --dark-text: #e0e0e0;
            --button: #4d0099;
            --button-hover: #4d0099;
            --light-bg: #ecf0f1;
            --light-frame: #ffffff;
            --light-accent: #9b59b6;
            --light-text: #2c3e50;
            --light-button: #8e44ad;
            --light-button-hover: #4d0099;
            --shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            --radius: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Vazirmatn', sans-serif;
            background: var(--dark-bg);
            color: var(--dark-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            font-size: 16px;
        }

        body.light {
            background: var(--light-bg);
            color: var(--light-text);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex-grow: 1;
            text-align: center;
            width: 100%;
        }

        @media (min-width: 601px) {
            .container { max-width: 60%; }
        }

        .header-section {
            background: var(--dark-frame);
            padding: 8px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            width: 100%;
            box-sizing: border-box;
        }

        body.light .header-section {
            background: var(--light-frame);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        h1 {
            font-size: 24px;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
        }

        h1 svg {
            margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 8px;
        }

        .token-box {
            font-size: 14px;
            background: rgba(255, 255, 255, 0.1);
            padding: 6px 12px;
            border-radius: 6px;
            margin-top: 8px;
        }

        body.light .token-box {
            background: rgba(0, 0, 0, 0.05);
            color: var(--light-text);
        }

        .section {
            background: var(--dark-frame);
            padding: 15px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            width: 100%;
        }

        body.light .section {
            background: var(--light-frame);
        }

        select, input, textarea, button {
            padding: 5px;
            border-radius: var(--radius);
            font-family: 'Vazirmatn', sans-serif;
            transition: var(--transition);
            font-size: 12px;
            vertical-align: middle;
        }

        select {
            background: var(--button);
            color: white;
            margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 15px;
        }

        body.light select {
            background: var(--light-button);
        }

        input, textarea {
            width: 100%;
            border: 1px solid var(--dark-accent);
            background: var(--dark-bg);
            color: var(--dark-text);
        }

        body.light input, body.light textarea {
            border: 1px solid var(--light-accent);
            background: var(--light-bg);
            color: var(--light-text);
        }

        button {
            background: var(--button);
            color: white;
            cursor: pointer;
            height: 40px;
        }

        button:hover {
            background: var(--button-hover);
        }

        body.light button {
            background: var(--light-button);
        }

        body.light button:hover {
            background: var(--light-button-hover);
        }

        .fetch-button {
            width: 50%;
            margin: 10px auto;
            display: block;
        }

        textarea {
            resize: vertical;
            height: 150px;
        }

        .response {
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            margin-top: 15px;
            text-align: center;
            transition: var(--transition);
            width: 100%;
            overflow-y: auto;
        }

        body.light .response {
            background: rgba(0, 0, 0, 0.05);
        }

        .chat-section {
            padding: 15px;
            margin-top: -5px;
            width: 100%;
        }

        .chat-box {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        #queryInput {
            flex-grow: 1;
            margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 10px;
            height: 40px;
        }

        .send-btn {
            padding: 0px;
            height: 40px;
            margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 3px;
        }

        #response {
            height: 70vh;
            overflow-y: auto;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--dark-accent);
            border-radius: var(--radius);
            padding: 15px;
        }

        body.light #response {
            background: rgba(0, 0, 0, 0.05);
            border: 1px solid var(--light-accent);
        }

        .answer {
            padding: 10px;
        }

        .feedback {
            margin-top: 8px;
        }

        .feedback button {
            background: var(--dark-accent);
            margin: 0 8px;
            padding: 6px 12px;
        }

        body.light .feedback button {
            background: var(--light-accent);
        }

        #correctionBox {
            display: none;
            margin-top: 8px;
        }

        #correctionBox input {
            width: 60%;
            margin: 0 8px;
        }

        #correctionBox button {
            background: var(--button);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: var(--dark-frame);
            margin: 15% auto;
            padding: 20px;
            width: 80%;
            max-width: 400px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
        }

        body.light .modal-content {
            background: var(--light-frame);
        }

        .modal-content input {
            margin: 10px 0;
        }

        .modal-content button {
            width: 100%;
            margin: 10px 0;
        }

        .processing {
            animation: blink 1s infinite;
        }

        @keyframes blink {
            50% { opacity: 0.5; }
        }

        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }
            .chat-section {
                padding: 10px;
            }
            .fetch-button {
                width: 70%;
            }
            #response {
                height: 60vh;
            }
            textarea {
                height: 100px;
            }
            .header-section .send-btn svg {
                width: 15px;
            }
            #logo-icon {
                width: 60px;
                height: 60px;
            }
            .header {
                flex-wrap: wrap;
                gap: 10px;
            }
            select {
                margin: 5px;
            }
            button {
                width: 30px;
            }
        }

        a {
            text-decoration: none;
            color: #87ceeb;
            transition: var(--transition);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <div class="header">
                <h1>
                    <svg id="logo-icon" version="1.0" xmlns="http://www.w3.org/2000/svg" width="68" height="68" viewBox="0 0 450 450" preserveAspectRatio="xMidYMid meet">
                        <g transform="translate(0,450) scale(0.1,-0.1)" fill="<?= isset($_GET['theme']) && $_GET['theme'] === 'light' ? '#2c3e50' : '#e0e0e0' ?>" stroke="none">
                            <path d="M1741 3774 c-114 -30 -215 -106 -282 -210 -36 -54 -46 -64 -71 -64 -39 0 -141 -35 -191 -67 -146 -90 -236 -251 -238 -427 l0 -81 -52 -40 c-59 -47 -125 -139 -158 -222 -21 -50 -24 -75 -24 -188 0 -145 15 -198 79 -293 l32 -49 -17 -52 c-76 -221 14 -471 216 -601 l62 -41 6 -62 c10 -122 71 -244 170 -339 54 -52 165 -113 225 -124 25 -4 54 -23 89 -56 180 -169 478 -140 639 62 l30 38 23 -34 c45 -66 144 -133 233 -159 147 -42 297 -7 413 98 28 24 65 47 83 50 118 23 273 146 336 266 37 71 66 171 66 231 0 31 5 38 45 59 76 40 160 133 207 227 40 82 42 89 46 197 3 93 0 123 -17 177 -20 64 -20 66 -2 90 157 215 127 519 -70 705 l-69 65 -4 93 c-5 142 -60 263 -160 355 -60 56 -173 110 -246 118 -54 6 -57 8 -79 50 -37 68 -139 159 -226 201 -66 31 -89 36 -171 41 -157 8 -287 -43 -376 -147 l-36 -42 -22 32 c-36 50 -135 117 -207 139 -78 24 -202 26 -282 4z m288 -159 c64 -35 105 -82 131 -153 19 -50 20 -79 20 -490 0 -327 3 -441 12 -450 13 -13 96 -16 115 -4 9 6 13 123 15 457 3 435 4 452 24 496 33 70 86 123 155 154 55 24 71 27 143 23 68 -3 91 -10 144 -38 77 -41 154 -123 179 -190 l19 -51 75 -3 c164 -6 293 -113 335 -276 16 -62 15 -115 -2 -206 -5 -29 -2 -33 48 -61 110 -63 179 -163 201 -290 19 -117 -13 -223 -98 -322 -25 -30 -44 -56 -42 -60 55 -101 62 -127 62 -226 0 -93 -2 -105 -33 -167 -40 -81 -129 -168 -199 -194 -70 -26 -72 -27 -66 -93 18 -201 -138 -402 -332 -426 -48 -6 -57 -11 -76 -41 -24 -40 -88 -86 -143 -104 -53 -17 -161 -8 -212 18 -91 46 -144 112 -169 209 -11 42 -15 114 -15 270 l0 213 147 0 147 0 63 -62 c61 -60 62 -63 56 -104 -7 -55 1 -85 33 -123 54 -64 154 -63 213 4 56 65 50 147 -16 205 -32 28 -41 31 -95 29 -59 -3 -59 -2 -111 49 l-52 52 95 95 95 95 60 0 c56 -1 61 -3 80 -33 34 -54 82 -79 141 -75 149 12 201 192 81 282 -22 16 -43 21 -90 21 -68 0 -102 -17 -132 -67 -15 -27 -19 -28 -97 -28 l-81 0 -122 -120 -121 -120 -147 0 -147 0 0 115 c0 106 -2 115 -21 126 -28 15 -105 3 -113 -17 -3 -9 -6 -188 -6 -398 0 -459 0 -458 -93 -551 -72 -71 -127 -95 -221 -95 -83 0 -150 32 -205 98 -37 43 -49 51 -89 56 -117 17 -246 117 -300 233 -21 48 -27 76 -30 159 l-4 100 -64 28 c-207 90 -297 331 -200 531 14 28 26 55 26 59 0 4 -23 35 -50 69 -121 149 -119 361 4 511 18 23 63 59 100 80 l68 40 -9 35 c-4 20 -8 74 -8 121 0 71 5 95 27 142 60 128 178 209 310 212 l73 1 26 56 c45 96 116 163 218 206 81 34 194 29 270 -12z m1175 -1676 c34 -27 32 -64 -5 -92 -24 -17 -30 -18 -55 -6 -40 19 -47 79 -12 103 30 21 40 20 72 -5z"/>
                            <path d="M1825 3446 c-37 -16 -82 -67 -91 -102 -4 -14 -4 -42 0 -62 5 -37 3 -40 -84 -127 -86 -86 -91 -89 -130 -86 -70 5 -140 -60 -140 -128 0 -70 63 -131 135 -131 35 0 47 -6 83 -43 l42 -43 0 -82 0 -82 -112 0 -113 0 -20 37 c-52 96 -180 122 -261 51 -45 -40 -64 -77 -64 -128 0 -174 229 -242 320 -94 l22 34 114 0 114 0 0 -63 0 -62 -134 -134 -135 -134 -55 -1 c-62 -1 -99 -22 -130 -75 -54 -92 13 -213 118 -213 55 0 101 24 127 65 16 26 19 44 16 93 l-5 62 149 148 149 149 0 233 0 233 -47 50 -48 51 0 74 -1 73 81 81 80 80 36 -12 c108 -33 218 74 189 183 -26 94 -120 142 -205 105z m99 -102 c19 -19 21 -54 3 -78 -20 -27 -77 -18 -96 15 -13 24 -13 29 2 53 20 30 66 35 91 10z m-629 -779 c14 -13 25 -36 25 -50 0 -33 -41 -75 -73 -75 -40 0 -77 36 -77 76 0 69 76 99 125 49z"/>
                            <path d="M2745 3350 l-59 -60 54 -55 c30 -30 59 -55 65 -55 14 0 115 102 115 116 0 9 -46 56 -96 97 -19 16 -22 15 -79 -43z"/>
                            <path d="M2782 3067 c-105 -40 -178 -157 -202 -324 -13 -93 1 -153 45 -192 48 -42 91 -51 236 -51 133 0 164 -9 158 -45 -11 -55 -185 -92 -484 -101 -295 -10 -428 12 -496 82 -33 34 -34 37 -37 134 -3 80 -7 100 -20 105 -55 21 -100 -228 -64 -351 52 -178 230 -237 646 -215 257 15 387 50 479 130 77 68 114 203 104 381 -13 213 -88 379 -199 436 -40 21 -125 27 -166 11z m108 -239 c43 -30 68 -65 76 -107 l7 -34 -96 5 c-100 5 -136 19 -157 57 -13 26 3 62 38 84 38 23 93 21 132 -5z"/>
                            <path d="M1772 2014 c-67 -34 -101 -128 -72 -199 6 -17 -15 -43 -131 -161 l-139 -141 0 -52 c0 -50 2 -54 67 -124 92 -100 87 -97 175 -97 76 0 78 -1 93 -30 30 -58 118 -80 184 -46 43 23 75 91 65 142 -9 48 -60 101 -107 109 -53 10 -85 -1 -130 -46 -36 -35 -44 -39 -91 -39 -49 0 -54 3 -104 53 -29 29 -52 62 -52 72 0 13 49 69 124 144 l125 124 60 1 c44 1 67 6 88 22 87 65 88 193 2 255 -41 29 -113 35 -157 13z m110 -101 c33 -30 18 -84 -28 -97 -25 -8 -74 27 -74 53 0 51 63 79 102 44z"/>
                        </g>
                    </svg>
                    <?= $lang === 'fa' ? 'فرادان' : 'Faradan' ?>
                </h1>
                <div>
                    <select onchange="location.href='?lang='+this.value+'&theme='+localStorage.getItem('theme')">
                        <option value="fa" <?= $lang === 'fa' ? 'selected' : '' ?>>فارسی</option>
                        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                    <button class="send-btn" onclick="toggleTheme()" style="width:30px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--dark-text)"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                    <button class="send-btn" onclick="showLogin()" style="width:30px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--dark-text)"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    </button>
                    <?php if ($isLoggedIn): ?>
                        <button class="send-btn" onclick="logout()" title="<?= $lang === 'fa' ? 'خروج' : 'Logout' ?>" style="width:30px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--dark-text)"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="token-box"><?= $lang === 'fa' ? "توکن‌ها: $tokenCount" : "Tokens: $tokenCount" ?></div>
        </div>
        <?php if ($isLoggedIn): ?>
            <div class="section">
                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="file" name="file" id="fileInput" accept=".txt,.html"><br><br>
                    <button type="button" class="fetch-button" onclick="fetchContent()"><?= $lang === 'fa' ? 'دریافت اطلاعات' : 'Fetch Data' ?></button>
                </form>
                <div id="fetchResponse" class="response"><?= $lang === 'fa' ? 'نتیجه اینجا ظاهر می‌شود' : 'Result will appear here' ?></div>
            </div>
            <div class="section">
                <textarea id="textInput" placeholder="<?= $lang === 'fa' ? 'متن رو اینجا بنویس...' : 'Write text here...' ?>" rows="3"></textarea>
                <button class="fetch-button" onclick="submitText()"><?= $lang === 'fa' ? 'ارسال متن' : 'Submit Text' ?></button>
                <div id="textResponse" class="response"><?= $lang === 'fa' ? 'نتیجه اینجا ظاهر می‌شود' : 'Result will appear here' ?></div>
            </div>
        <?php endif; ?>

        <div class="chat-section">
            <div class="container1">
                <div class="chat-box">
                    <input value=" " id="queryInput" type="text" placeholder="<?= $lang === 'fa' ? 'سوال خود را بپرسید' : 'Ask your question' ?>" autocomplete="off">
                    <button class="send-btn" onclick="getResponse()" style="width:40px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--dark-text)"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                    </button>
                    <button class="send-btn" onclick="toggleWiki()" title="Wikipedia" style="width:30px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?= $useWikipedia ? '#00cc00' : 'var(--dark-text)' ?>">
                            <path d="M12 2a10 10 0 110 20 10 10 0 010-20zm0 2a8 8 0 100 16 8 8 0 000-16zm-1 3v6l4 2-1 2-5-2V7h2z"/>
                        </svg>
                    </button>
                </div>
                <div id="response" class="response"><?= $lang === 'fa' ? 'پاسخ اینجا ظاهر می‌شود' : 'Response will appear here' ?></div>
            </div>
        </div>
        <div id="loginModal" class="modal" onclick="closeModal(event)">
            <div class="modal-content">
                <h2><?= $lang === 'fa' ? 'ورود' : 'Login' ?></h2>
                <input id="username" type="text" placeholder="<?= $lang === 'fa' ? 'نام کاربری' : 'Username' ?>" autocomplete="new-username">
                <input id="password" type="password" placeholder="<?= $lang === 'fa' ? 'رمز عبور' : 'Password' ?>" autocomplete="new-password">
                <button onclick="login()"><?= $lang === 'fa' ? 'ورود' : 'Login' ?></button>
                <?php if ($isLoggedIn): ?>
                    <h2><?= $lang === 'fa' ? 'تغییر رمز' : 'Change Password' ?></h2>
                    <input id="newPass" type="password" placeholder="<?= $lang === 'fa' ? 'رمز جدید' : 'New Password' ?>" autocomplete="new-password">
                    <button onclick="changePass()"><?= $lang === 'fa' ? 'تغییر' : 'Change' ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        function showLogin() { document.getElementById('loginModal').style.display = 'block'; }
        function closeModal(event) { if (event.target === document.getElementById('loginModal')) document.getElementById('loginModal').style.display = 'none'; }
        function login() {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'login=true&username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password)
            }).then(response => response.text()).then(data => {
                if (data === 'success') location.reload();
                else alert('<?= $lang === 'fa' ? 'ورود ناموفق!' : 'Login failed!' ?>');
            });
        }
        function logout() {
            fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'logout=true' })
            .then(response => response.text())
            .then(data => { if (data === 'success') location.href = '?lang=<?= $lang ?>&theme=' + localStorage.getItem('theme'); });
        }
        function changePass() {
            const newPass = document.getElementById('newPass').value;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'changePass=true&newPass=' + encodeURIComponent(newPass)
            }).then(response => response.text()).then(data => {
                if (data === 'success') alert('<?= $lang === 'fa' ? 'رمز تغییر کرد!' : 'Password changed!' ?>');
            });
        }
        function toggleWiki() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'toggleWiki=true'
            }).then(response => response.text()).then(data => {
                location.reload();
            });
        }
        function fetchContent() {
            const fileInput = document.getElementById('fileInput');
            const responseDiv = document.getElementById('fetchResponse');
            if (!fileInput.files.length) {
                responseDiv.innerText = '<?= $lang === 'fa' ? 'فایلی انتخاب نشده!' : 'No file selected!' ?>';
                return;
            }
            responseDiv.innerText = '<?= $lang === 'fa' ? 'در حال پردازش...' : 'Processing...' ?>';
            responseDiv.classList.add('processing');
            const formData = new FormData(document.getElementById('uploadForm'));
            fetch('', { method: 'POST', body: formData })
            .then(response => response.text())
            .then(data => {
                responseDiv.classList.remove('processing');
                responseDiv.innerText = data === 'success' ? '<?= $lang === 'fa' ? 'با موفقیت خوانده شد!' : 'Successfully fetched!' ?>' : 
                                       data === 'duplicate' ? '<?= $lang === 'fa' ? 'این فایل قبلاً دریافت شده!' : 'This file was already fetched!' ?>' : 
                                       '<?= $lang === 'fa' ? 'خطا در پردازش فایل!' : 'Error processing file!' ?>';
                if (data === 'success') setTimeout(() => location.reload(), 1000);
            });
        }
        function submitText() {
            const text = document.getElementById('textInput').value.trim();
            const responseDiv = document.getElementById('textResponse');
            if (!text) {
                responseDiv.innerText = '<?= $lang === 'fa' ? 'متن خالی است!' : 'Text is empty!' ?>';
                return;
            }
            responseDiv.innerText = '<?= $lang === 'fa' ? 'در حال پردازش...' : 'Processing...' ?>';
            responseDiv.classList.add('processing');
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'textInput=' + encodeURIComponent(text)
            }).then(response => response.text()).then(data => {
                responseDiv.classList.remove('processing');
                responseDiv.innerText = data === 'success' ? '<?= $lang === 'fa' ? 'با موفقیت خوانده شد!' : 'Successfully fetched!' ?>' : 
                                       data === 'duplicate' ? '<?= $lang === 'fa' ? 'این متن قبلاً دریافت شده!' : 'This text was already fetched!' ?>' : 
                                       '<?= $lang === 'fa' ? 'خطا در پردازش متن!' : 'Error processing text!' ?>';
                if (data === 'success') setTimeout(() => location.reload(), 1000);
            });
        }
        function getResponse() {
            const query = document.getElementById('queryInput').value.trim();
            const responseDiv = document.getElementById('response');
            if (!query) {
                responseDiv.innerText = '<?= $lang === 'fa' ? 'سوال خالی است!' : 'Query is empty!' ?>';
                return;
            }
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'query=true&queryInput=' + encodeURIComponent(query) + '&usedIds=' + encodeURIComponent('[]')
            }).then(response => response.text()).then(data => responseDiv.innerHTML = data);
        }
        function getMore(event) {
            event.preventDefault();
            const query = document.getElementById('queryInput').value.trim();
            const usedIds = document.getElementById('usedIds').value;
            const responseDiv = document.getElementById('response');
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'more=true&queryInput=' + encodeURIComponent(query) + '&usedIds=' + encodeURIComponent(usedIds)
            }).then(response => response.text()).then(data => responseDiv.innerHTML = data);
        }
        function submitFeedback(knowledgeId, rating) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'feedback=true&knowledgeId=' + knowledgeId + '&rating=' + rating
            }).then(response => response.text()).then(data => {
                if (data === 'success') {
                    alert('<?= $lang === 'fa' ? 'نظر شما ثبت شد!' : 'Feedback submitted!' ?>');
                    document.querySelector('.feedback').style.display = 'none';
                }
            });
        }
        function showCorrection() {
            document.getElementById('correctionBox').style.display = 'block';
        }
        function submitCorrection() {
            const query = document.getElementById('queryInput').value.trim();
            const correctAnswer = document.getElementById('correctAnswer').value.trim();
            if (!correctAnswer) return;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'correction=true&queryInput=' + encodeURIComponent(query) + '&correctAnswer=' + encodeURIComponent(correctAnswer)
            }).then(response => response.text()).then(data => {
                if (data === 'success') {
                    alert('<?= $lang === 'fa' ? 'جواب درست ثبت شد!' : 'Correct answer saved!' ?>');
                    document.getElementById('correctionBox').style.display = 'none';
                    getResponse();
                }
            });
        }
        function toggleTheme() {
            document.body.classList.toggle('light');
            localStorage.setItem('theme', document.body.classList.contains('light') ? 'light' : 'dark');
            const logoIcon = document.getElementById('logo-icon');
            if (document.body.classList.contains('light')) {
                logoIcon.querySelector('g').setAttribute('fill', '#2c3e50');
            } else {
                logoIcon.querySelector('g').setAttribute('fill', '#e0e0e0');
            }
        }
        if (localStorage.getItem('theme') === 'light') document.body.classList.add('light');
        else if (!localStorage.getItem('theme')) localStorage.setItem('theme', 'dark');
    </script>
</body>
</html>