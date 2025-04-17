<?php
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

define('DEFAULT_USER', 'admin');
define('DEFAULT_PASS', 'admin');

try {
    $db = new SQLite3('danadan.sqlite');
    $db->busyTimeout(5000);
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS qa (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT, answer TEXT, rating INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, qa_id INTEGER, rating INTEGER, FOREIGN KEY(qa_id) REFERENCES qa(id))");
    // جدول موقت با ساختار جدید (محدودیت یکتا روی word1 و word2)
    $db->exec("CREATE TABLE IF NOT EXISTS markov_chain_temp (id INTEGER PRIMARY KEY AUTOINCREMENT, word1 TEXT, word2 TEXT, count INTEGER DEFAULT 1, UNIQUE(word1, word2))");
    // انتقال داده‌ها از جدول قدیمی به جدول جدید (اگه جدول قدیمی وجود داشته باشه)
    $db->exec("INSERT OR IGNORE INTO markov_chain_temp (id, word1, word2, count) SELECT id, word1, word2, count FROM markov_chain");
    // حذف جدول قدیمی (اگه وجود داشته باشه)
    $db->exec("DROP TABLE IF EXISTS markov_chain");
    // تغییر نام جدول موقت به جدول اصلی
    $db->exec("ALTER TABLE markov_chain_temp RENAME TO markov_chain");
    $db->exec("CREATE TABLE IF NOT EXISTS markov_status (id INTEGER PRIMARY KEY, needs_rebuild INTEGER DEFAULT 1)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_question ON qa(question)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_word1 ON markov_chain(word1)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_word1_word2 ON markov_chain(word1, word2)");
} catch (Exception $e) {
    die("Table creation error: " . $e->getMessage());
}

try {
    $count = $db->querySingle("SELECT COUNT(*) FROM users");
    if ($count == 0) {
        $hashedPass = password_hash(DEFAULT_PASS, PASSWORD_DEFAULT);
        $db->exec("INSERT INTO users (username, password) VALUES ('" . DEFAULT_USER . "', '" . $hashedPass . "')");
    }
    $count = $db->querySingle("SELECT COUNT(*) FROM markov_status");
    if ($count == 0) {
        $db->exec("INSERT INTO markov_status (id, needs_rebuild) VALUES (1, 1)");
    }
} catch (Exception $e) {
    die("Initial setup error: " . $e->getMessage());
}

session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$lang = isset($_GET['lang']) ? $_GET['lang'] : 'fa';

function calculateSimilarity($query, $text) {
    $queryWords = array_filter(preg_split('/\s+/', strtolower($query)), fn($word) => strlen($word) > 2);
    $textWords = array_filter(preg_split('/\s+/', strtolower($text)), fn($word) => strlen($word) > 2);
    $common = array_intersect($queryWords, $textWords);
    return count($common) / (count($queryWords) + 0.1);
}

function buildMarkovChain($db) {
    $db->exec("UPDATE markov_status SET needs_rebuild = 0 WHERE id = 1");
    $db->exec("DELETE FROM markov_chain");
    $result = $db->query("SELECT answer FROM qa");
    
    $db->exec("BEGIN TRANSACTION");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $text = $row['answer'];
        $text = preg_replace('/([،؛.!؟])(?=\S|$)/u', '$1 ', $text);
        $text = preg_replace('/\x{200C}/u', ' ', $text);
        $tokens = array_values(array_filter(preg_split('/\s+/', $text), fn($token) => strlen($token) > 0));
        
        $words = [];
        foreach ($tokens as $token) {
            if (preg_match('/^[،؛.!؟]$/u', $token) || strlen($token) > 2) {
                $words[] = $token;
            }
        }

        for ($i = 0; $i < count($words) - 1; $i++) {
            $word1 = $words[$i];
            $word2 = $words[$i + 1];
            $stmt = $db->prepare("INSERT INTO markov_chain (word1, word2, count) VALUES (:word1, :word2, 1) ON CONFLICT(word1, word2) DO UPDATE SET count = count + 1");
            $stmt->bindValue(':word1', $word1, SQLITE3_TEXT);
            $stmt->bindValue(':word2', $word2, SQLITE3_TEXT);
            $stmt->execute();
        }
    }
    $db->exec("COMMIT");
}

function generateCreativeAnswer($db, $lang, $question = '', $minLength = 3, $maxLength = 10) {
    $needsRebuild = $db->querySingle("SELECT needs_rebuild FROM markov_status WHERE id = 1");
    if ($needsRebuild) {
        buildMarkovChain($db);
    }

    $question = preg_replace('/([،؛.!؟])(?=\S|$)/u', '$1 ', $question);
    $question = preg_replace('/\x{200C}/u', ' ', $question);
    $questionWords = array_values(array_filter(preg_split('/\s+/', $question), fn($word) => strlen($word) > 2 || preg_match('/^[،؛.!؟]$/u', $word)));

    static $markovCache = null;
    if ($markovCache === null) {
        $markovCache = [];
        $result = $db->query("SELECT word1, word2, count FROM markov_chain");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $word1 = $row['word1'];
            $word2 = $row['word2'];
            $count = $row['count'];
            if (!isset($markovCache[$word1])) {
                $markovCache[$word1] = [];
            }
            $markovCache[$word1][] = ['word2' => $word2, 'count' => $count];
        }
    }

    $startingWord = null;
    if (!empty($questionWords)) {
        foreach ($questionWords as $word) {
            if (isset($markovCache[$word])) {
                $startingWord = $word;
                break;
            }
        }
    }

    if (!$startingWord) {
        $words = array_keys($markovCache);
        if (empty($words)) return $lang === 'fa' ? 'نمی‌تونم چیزی جدید بسازم!' : 'Can’t create anything new!';
        $startingWord = $words[array_rand($words)];
    }

    $words = [];
    $currentWord = $startingWord;
    $words[] = $currentWord;
    $usedWords = [$currentWord];

    for ($i = 0; $i < $maxLength - 1; $i++) {
        if (!isset($markovCache[$currentWord])) break;

        $candidates = $markovCache[$currentWord];
        $totalCount = 0;
        foreach ($candidates as $candidate) {
            $totalCount += $candidate['count'];
        }

        if ($totalCount == 0) break;

        $rand = mt_rand(1, $totalCount);
        $cumulative = 0;
        $nextWord = null;
        foreach ($candidates as $candidate) {
            $cumulative += $candidate['count'];
            if ($rand <= $cumulative) {
                $nextWord = $candidate['word2'];
                break;
            }
        }

        if ($nextWord === null || in_array($nextWord, $usedWords)) {
            $unusedWords = array_diff(array_keys($markovCache), $usedWords);
            if (empty($unusedWords)) break;
            $nextWord = $unusedWords[array_rand($unusedWords)];
        }

        $currentWord = $nextWord;
        $words[] = $currentWord;
        $usedWords[] = $currentWord;

        if (count($words) >= $minLength && mt_rand(0, 1) == 1) {
            break;
        }
    }

    while (count($words) < $minLength) {
        $unusedWords = array_diff(array_keys($markovCache), $usedWords);
        if (empty($unusedWords)) break;
        $nextWord = $unusedWords[array_rand($unusedWords)];
        $words[] = $nextWord;
        $usedWords[] = $nextWord;
    }

    $sentence = implode(' ', $words);
    $sentence = preg_replace('/\s+([،؛.!؟])/u', '$1', $sentence);
    return $sentence ?: ($lang === 'fa' ? 'نمی‌تونم چیزی جدید بسازم!' : 'Can’t create anything new!');
}

function getAnswer($query, $db, $lang, $usedIds = [], &$knowledgeId = null, &$moreAvailable = false) {
    $query = trim($query);
    if (!$query) return ['answer' => $lang === 'fa' ? 'سوال خالیه!' : 'Query is empty!', 'showFeedback' => false];

    $stmt = $db->prepare("SELECT id, question, answer, rating FROM qa");
    $result = $stmt->execute();
    $matches = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!in_array($row['id'], $usedIds)) {
            $score = calculateSimilarity($query, $row['question']);
            if ($score > 0.3) {
                $matches[] = [
                    'id' => $row['id'],
                    'answer' => $row['answer'],
                    'score' => $score + ($row['rating'] * 0.1)
                ];
            }
        }
    }

    usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
    $moreAvailable = count($matches) > 1;

    if (!empty($matches)) {
        $knowledgeId = $matches[0]['id'];
        return [
            'answer' => htmlspecialchars($matches[0]['answer']) . ($moreAvailable ? ' <a href="#" onclick="getMore(event)">[...]</a>' : ''),
            'showFeedback' => true
        ];
    }

    return [
        'answer' => $lang === 'fa' ? 'اطلاعات کافی نیست!' : 'Not enough information!',
        'showFeedback' => false
    ];
}

function getStats($db) {
    return [
        'qa_count' => $db->querySingle("SELECT COUNT(DISTINCT question) FROM qa") ?? 0,
        'feedback_count' => $db->querySingle("SELECT COUNT(*) FROM feedback") ?? 0
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
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
        } elseif (isset($_POST['changePass']) && $isLoggedIn) {
            $newPass = trim($_POST['newPass'] ?? '');
            if ($newPass) {
                $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
                $db->exec("UPDATE users SET password = '$hashedPass' WHERE username = '" . DEFAULT_USER . "'");
                echo 'success';
            } else {
                echo 'empty';
            }
            exit;
        } elseif (isset($_POST['train']) && $isLoggedIn) {
            $question = $_POST['question'] ?? '';
            $answer = $_POST['answer'] ?? '';
            $stmt = $db->prepare("INSERT INTO qa (question, answer) VALUES (:question, :answer)");
            $stmt->bindValue(':question', $question, SQLITE3_TEXT);
            $stmt->bindValue(':answer', $answer, SQLITE3_TEXT);
            $stmt->execute();
            $db->exec("UPDATE markov_status SET needs_rebuild = 1 WHERE id = 1");
            echo 'success';
            exit;
        } elseif (isset($_POST['generate']) && $isLoggedIn) {
            $question = $_POST['question'] ?? '';
            echo generateCreativeAnswer($db, $lang, $question, 3, 10);
            exit;
        } elseif (isset($_POST['saveGenerated']) && $isLoggedIn) {
            $question = $_POST['question'] ?? '';
            $answer = $_POST['answer'] ?? '';
            $stmt = $db->prepare("INSERT INTO qa (question, answer) VALUES (:question, :answer)");
            $stmt->bindValue(':question', $question, SQLITE3_TEXT);
            $stmt->bindValue(':answer', $answer, SQLITE3_TEXT);
            $stmt->execute();
            $db->exec("UPDATE markov_status SET needs_rebuild = 1 WHERE id = 1");
            echo 'success';
            exit;
        } elseif (isset($_POST['query'])) {
            $query = trim($_POST['query']);
            $usedIds = json_decode($_POST['usedIds'] ?? '[]', true);
            $knowledgeId = null;
            $moreAvailable = false;
            $responseData = getAnswer($query, $db, $lang, $usedIds, $knowledgeId, $moreAvailable);
            $usedIds[] = $knowledgeId;
            $response = '<div class="chat-message bot"><div class="bubble">' . $responseData['answer'] . '</div>';
            if ($responseData['showFeedback'] && $knowledgeId) {
                $response .= '<div class="feedback"><button onclick="submitFeedback(' . $knowledgeId . ', 1)">✓</button></div>';
            }
            $response .= '</div><input type="hidden" id="usedIds" value=\'' . json_encode($usedIds) . '\'>';
            echo $response;
            exit;
        } elseif (isset($_POST['more'])) {
            $query = trim($_POST['query']);
            $usedIds = json_decode($_POST['usedIds'] ?? '[]', true);
            $knowledgeId = null;
            $moreAvailable = false;
            $responseData = getAnswer($query, $db, $lang, $usedIds, $knowledgeId, $moreAvailable);
            $usedIds[] = $knowledgeId;
            $response = '<div class="chat-message bot"><div class="bubble">' . $responseData['answer'] . '</div>';
            if ($responseData['showFeedback'] && $knowledgeId) {
                $response .= '<div class="feedback"><button onclick="submitFeedback(' . $knowledgeId . ', 1)">✓</button></div>';
            }
            $response .= '</div><input type="hidden" id="usedIds" value=\'' . json_encode($usedIds) . '\'>';
            echo $response;
            exit;
        } elseif (isset($_POST['feedback'])) {
            $qaId = (int)($_POST['qaId'] ?? 0);
            $rating = (int)($_POST['rating'] ?? 0);
            if ($qaId && $rating !== 0) {
                $stmt = $db->prepare("INSERT INTO feedback (qa_id, rating) VALUES (:qa_id, :rating)");
                $stmt->bindValue(':qa_id', $qaId, SQLITE3_INTEGER);
                $stmt->bindValue(':rating', $rating, SQLITE3_INTEGER);
                $stmt->execute();
                $stmt = $db->prepare("UPDATE qa SET rating = rating + :rating WHERE id = :id");
                $stmt->bindValue(':rating', $rating, SQLITE3_INTEGER);
                $stmt->bindValue(':id', $qaId, SQLITE3_INTEGER);
                $stmt->execute();
            }
            echo 'success';
            exit;
        }
    } catch (Exception $e) {
        echo 'success';
        exit;
    }
}

$stats = getStats($db);
?>

<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'fa' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دانادان | Danadan</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #1e2a44;
            --dark-frame: #2c3e50;
            --dark-accent: #8e44ad;
            --dark-text: #e0e0e0;
            --button: #4d0099;
            --button-hover: #6b00cc;
            --light-bg: #ecf0f1;
            --light-frame: #ffffff;
            --light-accent: #9b59b6;
            --light-text: #2c3e50;
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
            font-size: 16px;
        }

        body.light {
            background: var(--light-bg);
            color: var(--light-text);
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 10px;
            flex-grow: 1;
        }

        @media (min-width: 601px) {
            .container {
                width: 60%;
                padding: 20px;
            }
        }

        .header {
            background: var(--dark-frame);
            padding: 10px 15px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        body.light .header {
            background: var(--light-frame);
        }

        h1 {
            font-size: 24px;
            display: flex;
            align-items: center;
        }

        h1 svg {
            margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 10px;
        }

        .header div {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        select, input, button {
            padding: 8px;
            border-radius: var(--radius);
            font-family: 'Vazirmatn', sans-serif;
        }

        select {
            background: var(--button);
            color: white;
            border: none;
            cursor: pointer;
        }

        body.light select {
            background: var(--light-accent);
            color: var(--light-text);
        }

        button {
            background: var(--button);
            color: white;
            border: none;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        button:hover {
            background: var(--button-hover);
        }

        button svg {
            stroke: white !important;
        }

        body.light button svg {
            stroke: var(--light-text) !important;
        }

        @media (max-width: 600px) {
            button {
                width: 30px;
                height: 30px;
            }
            .header div {
                gap: 5px;
            }
            select {
                padding: 5px;
            }
        }

        .chat-box {
            background: var(--dark-frame);
            border-radius: var(--radius);
            padding: 10px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        body.light .chat-box {
            background: var(--light-frame);
        }

        #queryInput {
            flex-grow: 1;
            border: 1px solid var(--dark-accent);
            background: var(--dark-bg);
            color: var(--dark-text);
            margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 10px;
        }

        body.light #queryInput {
            border: 1px solid var(--light-accent);
            background: var(--light-bg);
            color: var(--light-text);
        }

        .chat-messages {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            margin-bottom: 10px;
            height: calc(100vh - 150px);
        }

        <?php if ($isLoggedIn): ?>
        .chat-messages {
            min-height: 50vh;
            height: auto;
        }
        <?php endif; ?>

        body.light .chat-messages {
            background: rgba(0, 0, 0, 0.05);
        }

        .chat-message {
            margin: 10px 0;
            display: flex;
            width: 100%;
        }

        .chat-message.user .bubble {
            max-width: 70%;
            padding: 10px;
            border-radius: var(--radius);
            background: var(--button);
            <?= $lang === 'fa' ? 'margin-left: auto; margin-right: 0;' : 'margin-right: auto; margin-left: 0;' ?>
        }

        .chat-message.bot .bubble {
            max-width: 70%;
            padding: 10px;
            border-radius: var(--radius);
            background: var(--dark-accent);
            <?= $lang === 'fa' ? 'margin-right: auto; margin-left: 0;' : 'margin-left: auto; margin-right: 0;' ?>
        }

        body.light .bubble {
            background: var(--light-accent);
        }

        body.light .chat-message.user .bubble {
            background: var(--light-accent);
        }

        .feedback {
            margin-top: 5px;
        }

        .feedback button {
            margin: 0 5px;
            padding: 5px 10px;
        }

        .admin-section {
            background: var(--dark-frame);
            padding: 15px;
            border-radius: var(--radius);
            margin-top: 20px;
        }

        body.light .admin-section {
            background: var(--light-frame);
        }

        .admin-section input, .admin-section button {
            margin: 5px 0;
            width: 100%;
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
            text-align: center;
        }

        body.light .modal-content {
            background: var(--light-frame);
        }

        .modal-content input {
            margin: 10px 0;
            width: 100%;
        }

        .stats {
            background: var(--dark-frame);
            padding: 10px;
            border-radius: var(--radius);
            margin-top: 10px;
            text-align: center;
        }

        body.light .stats {
            background: var(--light-frame);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <svg width="40" height="40" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="40" fill="none" stroke="var(--dark-text)" stroke-width="5"/>
                    <circle cx="50" cy="50" r="20" fill="var(--dark-accent)"/>
                    <circle cx="50" cy="50" r="10" fill="white"/>
                </svg>
                <?= $lang === 'fa' ? 'دانادان' : 'Danadan' ?>
            </h1>
            <div>
                <select onchange="location.href='?lang='+this.value">
                    <option value="fa" <?= $lang === 'fa' ? 'selected' : '' ?>>فارسی</option>
                    <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                </select>
                <button onclick="toggleTheme()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
                <button onclick="showLogin()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </button>
                <?php if ($isLoggedIn): ?>
                    <button onclick="logout()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <form onsubmit="event.preventDefault();">
        <div class="chat-box">
            <input id="queryInput" type="text" placeholder="<?= $lang === 'fa' ? 'سوال خود را بپرسید...' : 'Ask your question...' ?>" autocomplete="off">
            <button onclick="sendQuery()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white">
                    <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                </svg>
            </button>
        </div>
        </form>
        <div class="chat-messages" id="chatMessages"></div>
        <?php if ($isLoggedIn): ?>
            <div class="admin-section">
                <h2><?= $lang === 'fa' ? 'آموزش سیستم' : 'Train System' ?></h2>
                <form onsubmit="event.preventDefault();">
                <input id="trainQuestion" type="text" placeholder="<?= $lang === 'fa' ? 'سوال' : 'Question' ?>">
                <input id="trainAnswer" type="text" placeholder="<?= $lang === 'fa' ? 'جواب' : 'Answer' ?>">
                <button onclick="trainSystem()"><?= $lang === 'fa' ? 'آموزش' : 'Train' ?></button>
                <button onclick="generateAnswer()"><?= $lang === 'fa' ? 'تولید جمله' : 'Generate Answer' ?></button>
                </form>
                <div id="generatedAnswer"></div>
                <div class="stats">
                    <p><?= $lang === 'fa' ? "تعداد سوالات: {$stats['qa_count']}" : "Questions: {$stats['qa_count']}" ?></p>
                    <p><?= $lang === 'fa' ? "تعداد بازخوردها: {$stats['feedback_count']}" : "Feedbacks: {$stats['feedback_count']}" ?></p>
                </div>
            </div>
        <?php endif; ?>
        <div id="loginModal" class="modal" onclick="closeModal(event)">
            <div class="modal-content">
                <h2><?= $lang === 'fa' ? ($isLoggedIn ? 'تغییر رمز' : 'ورود') : ($isLoggedIn ? 'Change Password' : 'Login') ?></h2>
                <?php if (!$isLoggedIn): ?>
                    <input id="username" type="text" placeholder="<?= $lang === 'fa' ? 'نام کاربری' : 'Username' ?>">
                    <input id="password" type="password" placeholder="<?= $lang === 'fa' ? 'رمز عبور' : 'Password' ?>">
                    <button onclick="login()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </button>
                <?php else: ?>
                    <input id="newPass" type="password" placeholder="<?= $lang === 'fa' ? 'رمز جدید' : 'New Password' ?>">
                    <button onclick="changePass()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white">
                            <path d="M12 14l9-5-9-5-9 5 9 5z"/>
                            <path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        window.currentQuery = '';

        function sendQuery() {
            const queryInput = document.getElementById('queryInput');
            const chatMessages = document.getElementById('chatMessages');
            const query = queryInput.value.trim();
            if (!query) return;

            window.currentQuery = query;

            chatMessages.innerHTML = '';

            chatMessages.innerHTML += `<div class="chat-message user"><div class="bubble">${query}</div></div>`;
            queryInput.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `query=${encodeURIComponent(query)}&usedIds=${encodeURIComponent('[]')}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                chatMessages.innerHTML += data;
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function getMore(event) {
            event.preventDefault();
            const chatMessages = document.getElementById('chatMessages');
            const usedIds = document.getElementById('usedIds').value;

            const query = window.currentQuery;
            if (!query) {
                chatMessages.innerHTML += `<div class="chat-message bot"><div class="bubble"><?= $lang === 'fa' ? 'سوال خالیه!' : 'Query is empty!' ?></div></div>`;
                return;
            }

            chatMessages.innerHTML = `<div class="chat-message user"><div class="bubble">${query}</div></div>`;

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `more=true&query=${encodeURIComponent(query)}&usedIds=${encodeURIComponent(usedIds)}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                chatMessages.innerHTML += data;
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function submitFeedback(qaId, rating) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `feedback=true&qaId=${qaId}&rating=${rating}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                if (data === 'success') alert('<?= $lang === 'fa' ? 'بازخورد ثبت شد!' : 'Feedback submitted!' ?>');
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function trainSystem() {
            const question = document.getElementById('trainQuestion').value;
            const answer = document.getElementById('trainAnswer').value;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `train=true&question=${encodeURIComponent(question)}&answer=${encodeURIComponent(answer)}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                console.log('trainSystem response:', data);
                alert('<?= $lang === 'fa' ? 'آموزش داده شد!' : 'Training saved!' ?>');
                document.getElementById('trainQuestion').value = '';
                document.getElementById('trainAnswer').value = '';
                location.reload();
            }).catch(error => {
                console.error('Error:', error);
                alert('<?= $lang === 'fa' ? 'آموزش داده شد!' : 'Training saved!' ?>');
                document.getElementById('trainQuestion').value = '';
                document.getElementById('trainAnswer').value = '';
                location.reload();
            });
        }

        function generateAnswer() {
            const question = document.getElementById('trainQuestion').value.trim();
            const generatedAnswerDiv = document.getElementById('generatedAnswer');
            if (!question) {
                generatedAnswerDiv.innerHTML = '<?= $lang === 'fa' ? 'لطفاً سوال وارد کنید!' : 'Please enter a question!' ?>';
                return;
            }
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `generate=true&question=${encodeURIComponent(question)}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                const errorMessageFA = 'نمی‌تونم چیزی جدید بسازم!';
                const errorMessageEN = 'Can’t create anything new!';
                if (data === errorMessageFA || data === errorMessageEN) {
                    generatedAnswerDiv.innerHTML = `<p>${data}</p>`;
                } else {
                    generatedAnswerDiv.innerHTML = `<p>${data}</p><button onclick="saveGenerated('${question}', '${data.replace(/'/g, "\\'")}')"><?= $lang === 'fa' ? 'تأیید' : 'Confirm' ?></button>`;
                }
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function saveGenerated(question, answer) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `saveGenerated=true&question=${encodeURIComponent(question)}&answer=${encodeURIComponent(answer)}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                console.log('saveGenerated response:', data);
                alert('<?= $lang === 'fa' ? 'جمله ذخیره شد!' : 'Answer saved!' ?>');
                location.reload();
            }).catch(error => {
                console.error('Error:', error);
                alert('<?= $lang === 'fa' ? 'جمله ذخیره شد!' : 'Answer saved!' ?>');
                location.reload();
            });
        }

        function login() {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `login=true&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                if (data === 'success') location.reload();
                else alert('<?= $lang === 'fa' ? 'ورود ناموفق!' : 'Login failed!' ?>');
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function changePass() {
            const newPass = document.getElementById('newPass').value;
            if (!newPass) return;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `changePass=true&newPass=${encodeURIComponent(newPass)}`,
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                if (data === 'success') {
                    alert('<?= $lang === 'fa' ? 'رمز تغییر کرد!' : 'Password changed!' ?>');
                    location.reload();
                }
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function logout() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'logout=true',
                cache: 'no-store'
            }).then(response => response.text()).then(data => {
                if (data === 'success') location.reload();
            }).catch(error => {
                alert('<?= $lang === 'fa' ? 'خطا در ارتباط با سرور!' : 'Server connection error!' ?>');
            });
        }

        function showLogin() {
            document.getElementById('loginModal').style.display = 'block';
        }

        function closeModal(event) {
            if (event.target === document.getElementById('loginModal')) {
                document.getElementById('loginModal').style.display = 'none';
            }
        }

        function toggleTheme() {
            document.body.classList.toggle('light');
            localStorage.setItem('theme', document.body.classList.contains('light') ? 'light' : 'dark');
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme') === 'light') document.body.classList.add('light');
            document.getElementById('queryInput').addEventListener('keypress', e => {
                if (e.key === 'Enter') sendQuery();
            });
        });
    </script>
</body>
</html>