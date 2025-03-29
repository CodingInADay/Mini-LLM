<?php
header('Content-Type: text/html; charset=UTF-8');

// ثابت‌ها
define('DEFAULT_USER', 'admin');
define('DEFAULT_PASS', 'password');

// اتصال به دیتابیس
$db = new SQLite3('self_train_llm.sqlite');

// ساخت دیتابیس اگه وجود نداشت
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL UNIQUE,
    title TEXT,
    content TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS knowledge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER,
    sentence TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(page_id) REFERENCES pages(id)
)");
$db->exec("CREATE TABLE IF NOT EXISTS word_index (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    word TEXT NOT NULL,
    knowledge_id INTEGER,
    frequency INTEGER DEFAULT 1,
    FOREIGN KEY(knowledge_id) REFERENCES knowledge(id)
)");
$db->exec("CREATE TABLE IF NOT EXISTS feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    knowledge_id INTEGER,
    rating INTEGER,
    FOREIGN KEY(knowledge_id) REFERENCES knowledge(id)
)");
$db->exec("CREATE TABLE IF NOT EXISTS corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    query TEXT NOT NULL UNIQUE,
    answer TEXT NOT NULL
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_word ON word_index(word)");

// چک کردن کاربر اولیه
$stmt = $db->prepare("SELECT COUNT(*) FROM users");
$result = $stmt->execute()->fetchArray()[0];
if ($result == 0) {
    $hashedPass = password_hash(DEFAULT_PASS, PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username, password) VALUES ('" . DEFAULT_USER . "', '" . $hashedPass . "')");
}

// مدیریت سشن
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// زبان پیش‌فرض
$lang = isset($_GET['lang']) ? $_GET['lang'] : 'fa';

// تابع پردازش محتوا
function fetchWebContent($fileContent, $url, $db) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM pages WHERE url = :url");
    if ($stmt === false) return 'error';
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result && $result->fetchArray()[0] > 0) return 'duplicate';
    
    $content = $fileContent;
    $title = 'Uploaded File';
    $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\n\r\t]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    $stmt = $db->prepare("INSERT INTO pages (url, title, content) VALUES (:url, :title, :content)");
    if ($stmt === false) return 'error';
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
            if ($stmt === false) continue;
            $stmt->bindValue(':page_id', $pageId, SQLITE3_INTEGER);
            $stmt->bindValue(':sentence', $sentence, SQLITE3_TEXT);
            $stmt->execute();
            
            $knowledgeId = $db->lastInsertRowID();
            $words = preg_split('/\s+/', mb_strtolower($sentence));
            foreach ($words as $word) {
                if (strlen($word) > 2 && !in_array($word, ['است', 'این', 'که', 'در', 'و', 'از'])) {
                    $stmt = $db->prepare("INSERT OR IGNORE INTO word_index (word, knowledge_id) VALUES (:word, :knowledge_id)");
                    if ($stmt === false) continue;
                    $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                    $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt = $db->prepare("UPDATE word_index SET frequency = frequency + 1 WHERE word = :word AND knowledge_id = :knowledge_id");
                    if ($stmt === false) continue;
                    $stmt->bindValue(':word', $word, SQLITE3_TEXT);
                    $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
                    $stmt->execute();
                }
            }
        }
    }
    return true;
}

// تابع جستجوی پاسخ
function getAnswer($query, $db, $lang, &$moreAvailable = false, &$knowledgeId = null, $usedIds = []) {
    $query = trim(mb_strtolower($query));
    $stmt = $db->prepare("SELECT answer FROM corrections WHERE query = :query");
    $stmt->bindValue(':query', $query, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($result) return $result['answer'];

    $words = preg_split('/\s+/', $query);
    $stopWords = $lang === 'fa' ? ['کجاست', 'چیست', 'چه', 'کیست', 'است', 'در', 'با', 'به'] : ['where', 'is', 'what', 'who', 'a', 'an', 'the', 'in', 'on', 'at'];
    $keywords = array_filter($words, fn($word) => !in_array(mb_strtolower($word), $stopWords));
    
    if (empty($keywords)) return $lang === 'fa' ? 'سوال نامشخصه!' : 'Unclear question!';
    
    $keywordWeights = [];
    $index = 0;
    foreach ($keywords as $keyword) {
        $keywordWeights[$keyword] = count($keywords) - $index;
        $index++;
    }
    
    $conditions = array_map(fn($keyword) => "word LIKE '%" . $db->escapeString(mb_strtolower($keyword)) . "%'", $keywords);
    $whereClause = implode(' OR ', $conditions);
    
    $sql = "SELECT DISTINCT knowledge_id FROM word_index WHERE " . $whereClause;
    $result = $db->query($sql);
    $knowledgeIds = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!in_array($row['knowledge_id'], $usedIds)) {
            $knowledgeIds[] = $row['knowledge_id'];
        }
    }
    
    if (empty($knowledgeIds)) return $lang === 'fa' ? 'چیزی پیدا نکردم!' : 'Nothing found!';
    
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
                $score += $keywordWeights[$keyword];
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
    
    $moreAvailable = count($sentences) > 1;
    $selected = $sentences[0] ?? null;
    if ($selected) {
        $knowledgeId = $selected['id'];
        $sentence = htmlspecialchars($selected['sentence']);
        $moreLink = $moreAvailable ? '<a href="#" onclick="getMore(event)">[' . ($lang === 'fa' ? 'بیشتر' : 'More') . ']</a>' : '';
        return $sentence . ' ' . $moreLink;
    }
    return $lang === 'fa' ? 'چیزی پیدا نکردم!' : 'Nothing found!';
}

// تعداد توکن‌ها
$tokenCount = $db->querySingle("SELECT COUNT(DISTINCT word) FROM word_index");

// پردازش درخواست‌ها
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $stmt = $db->prepare("SELECT password FROM users WHERE username = :username");
        if ($stmt === false) { echo 'error'; exit; }
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
        $answer = getAnswer($query, $db, $lang, $moreAvailable, $knowledgeId, $usedIds);
        $usedIds[] = $knowledgeId;
        $response = '<div class="answer">' . $answer . '</div>';
        if ($knowledgeId) {
            $response .= '<div class="feedback">' .
                         '<button onclick="submitFeedback(' . $knowledgeId . ', 1)"> بله - Yes </button>' .
                         '<button onclick="showCorrection()"> نه - No </button>' .
                         '<div id="correctionBox" style="display:none; margin-top: 5px;">' .
                         ($lang === 'fa' ? 'جواب درست: ' : 'Correct answer: ') .
                         '<input type="text" id="correctAnswer">' .
                         '<button onclick="submitCorrection()">ثبت</button></div></div>';
        }
        $response .= '<input type="hidden" id="usedIds" value=\'' . json_encode($usedIds) . '\'>';
        echo $response;
        exit;
    } elseif (isset($_POST['more'])) {
        $query = trim($_POST['queryInput']);
        $usedIds = json_decode($_POST['usedIds'] ?? '[]', true);
        $moreAvailable = false;
        $knowledgeId = null;
        $answer = getAnswer($query, $db, $lang, $moreAvailable, $knowledgeId, $usedIds);
        $usedIds[] = $knowledgeId;
        $response = '<div class="answer">' . $answer . '</div>';
        if ($knowledgeId) {
            $response .= '<div class="feedback">' .
                         '<button onclick="submitFeedback(' . $knowledgeId . ', 1)">بله</button>' .
                         '<button onclick="showCorrection()">خیر</button>' .
                         '<div id="correctionBox" style="display:none; margin-top: 5px;">' .
                         ($lang === 'fa' ? 'جواب درست: ' : 'Correct answer: ') .
                         '<input type="text" id="correctAnswer">' .
                         '<button onclick="submitCorrection()">ثبت</button></div></div>';
        }
        $response .= '<input type="hidden" id="usedIds" value=\'' . json_encode($usedIds) . '\'>';
        echo $response;
        exit;
    } elseif (isset($_POST['feedback'])) {
        $knowledgeId = $_POST['knowledgeId'] ?? 0;
        $rating = $_POST['rating'] ?? 0;
        $stmt = $db->prepare("INSERT INTO feedback (knowledge_id, rating) VALUES (:knowledge_id, :rating)");
        $stmt->bindValue(':knowledge_id', $knowledgeId, SQLITE3_INTEGER);
        $stmt->bindValue(':rating', $rating, SQLITE3_INTEGER);
        $stmt->execute();
        echo 'success';
        exit;
    } elseif (isset($_POST['correction'])) {
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
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang === 'fa' ? 'داناپی' : 'Danapey' ?></title>
    <link rel="icon" href="https://stackoverflow.com/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;700&display=swap" rel="stylesheet">


<style>
    :root {
        --primary: #2c3e50;
        --secondary: #34495e;
        --accent: #7f8c8d;
        --bg-dark: #2c3e50;
        --bg-light: #f5f6f5;
        --text-dark: #ecf0f1;
        --text-light: #2c3e50;
        --shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        --transition: all 0.3s ease;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Vazirmatn', sans-serif;
        background: var(--bg-dark);
        color: var(--text-dark);
            background-color: #656565;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        direction: <?= $lang === 'fa' ? 'rtl' : 'ltr' ?>;
        transition: var(--transition);
    }

    body.light {
        background: var(--bg-light);
        color: var(--text-light);
    }

    a {
        text-decoration: none;
        color: #87ceeb;
        transition: var(--transition);
    }

    .container {
        max-width: 1200px;
        margin: 0 auto; /* وسط‌چین کردن */
        padding: 20px;
        flex-grow: 1;
        text-align: center;
        width: 100%; /* عرض کامل برای container */
    }

    @media (min-width: 601px) {
        .container {
            max-width: 60%; /* 70 درصد در دسکتاپ */
        }
    }

    .header-section {
    	margin: 10px;
        background: var(--bg-dark);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid var(--primary);
        box-shadow: var(--shadow);
        transition: var(--transition);
        margin-bottom: 20px;
        width: 100%; /* عرض کامل نسبت به container */
    }

    .header {
    	margin: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%; /* عرض کامل برای header */
    }

    body.light .header-section {
        background: white;
        border-color: var(--primary);
    }

    h1 {
        font-size: 24px;
        color: var(--text-dark);
        display: flex;
        align-items: center;
    }

    body.light h1 {
        color: var(--primary);
    }

    h1 svg {
        margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 5px;
    }

    .token-box {
        text-align: center;
        font-size: 12px;
        background: rgba(255, 255, 255, 0.1);
        padding: 5px 10px;
        border-radius: 4px;
        margin-top: 5px;
        color: var(--text-dark);
        width: 100%; /* عرض کامل برای token-box */
    }

    body.light .token-box {
        background: rgba(0, 0, 0, 0.05);
        color: rgba(44, 62, 80, 0.6);
    }

    .section {
    	margin: 10px;
        background: var(--bg-dark);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid var(--primary);
        border-color: gray;
        box-shadow: var(--shadow);
        margin-bottom: 10px;
        transition: var(--transition);
        width: 100%; /* عرض کامل برای section */
    }

    body.light .section {
        background: white;
        border-color: var(--primary);
    }

    /* تنظیمات برای فرم آپلود و ورود متن */
    #uploadForm, #textInput {
        width: 100%; /* عرض کامل برای فرم و textarea */
    }

    #fileInput, #textInput {
        width: 100%; /* عرض کامل برای اینپوت فایل و textarea */
        margin-bottom: 10px;
    }

    select, input, textarea, button {
        padding: 10px;
        #border: 1px solid var(--primary);
        border-radius: 4px;
        font-family: 'Vazirmatn', sans-serif;
        transition: var(--transition);
    }

    select:focus, input:focus, textarea:focus {
        outline: none;
        border-color: var(--secondary);
    }

    button {
        background: var(--secondary);
        color: white;
        #border: none;
        cursor: pointer;
        transition: var(--transition);
    }

    button:hover {
        background: #2c3e50;
    }

    body.light button {
        background: #3498db;
    }

    body.light button:hover {
        background: #2980b9;
    }

    .fetch-button {
        width: 50%;
        margin: 10px auto;
        display: block;
    }

    textarea {
        width: 100%;
        resize: vertical;
    }

    .response {
        padding: 10px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
        margin-top: 10px;
        text-align: center;
        color: rgba(236, 240, 241, 0.6);
        transition: var(--transition);
        width: 100%; /* عرض کامل برای response */
    }

    body.light .response {
        background: #ecf0f1;
        color: rgba(44, 62, 80, 0.6);
    }

    .chat-section {
    	margin: 10px;
        padding: 20px;
        margin-bottom: 20px;
        width: 100%; /* عرض کامل برای chat-section */
    }

    .chat-box {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
        width: 100%; /* عرض کامل برای chat-box */
    }

    #queryInput {
        flex-grow: 1;
        margin-<?= $lang === 'fa' ? 'left' : 'right' ?>: 10px;
    }

    .send-btn {
        padding: 10px;
        #background: none;
        #border: none;
    }

    #response {
        height: calc(100vh - 200px);
        overflow-y: auto;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
        border: 1px solid var(--primary);
        padding: 10px;
        transition: var(--transition);
        width: 100%; /* عرض کامل برای response */
                     background-color: #414141;
    }

    body.light #response {
        background: #ecf0f1;
        #border-color: var(--primary);
    }

    .answer {
        padding: 10px;
    }

    .feedback {
        text-align: center;
        margin-top: 5px;
        font-size: 14px;
        color: rgba(236, 240, 241, 0.6);
    }

    body.light .feedback {
        color: rgba(44, 62, 80, 0.6);
    }

    .feedback button {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid var(--accent);
        color: inherit;
        padding: 5px 10px;
        margin: 0 5px;
        border-radius: 4px;
        cursor: pointer;
        transition: var(--transition);
    }

    body.light .feedback button {
        background: rgba(0, 0, 0, 0.05);
    }

    #correctionBox {
        display: none;
        margin-top: 5px;
    }

    #correctionBox input {
        width: 60%;
        margin: 0 5px;
    }

    #correctionBox button {
        padding: 5px 10px;
        background: var(--accent);
        color: white;
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
        background: var(--bg-dark);
        margin: 15% auto;
        padding: 20px;
        width: 80%;
        max-width: 400px;
        border-radius: 8px;
        border: 1px solid var(--primary);
        text-align: center;
        transition: var(--transition);
    }

    body.light .modal-content {
        background: white;
        #border-color: var(--primary);
    }

    .modal-content input {
        width: calc(100% - 22px);
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
            padding: 0;
        }
        .chat-section {
            padding: 10px;
        }
        .fetch-button {
            width: 70%;
        }
        #response {
            height: calc(100vh - 250px);
        }
        #queryInput {
            width: calc(100% - 60px);
        }
        .feedback button {
            font-size: 13px;
        }
    }

    select {
        #position: absolute;
        top: 20px;
        left: 20px;
        padding: 5px;
        font-family: 'Vazirmatn', sans-serif;
        border-radius: 5px;
        background: #0288d1;
        color: #fff;
        #border: none;
    }
</style>


</head>
<body>
	
    <div class="container">
        <div class="header-section">
            <div class="header">
          
                    <h1>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7f8c8d" stroke-width="2" onclick="showLogin()" style="cursor: pointer;">
                            <path d="M12 2l-2 7h4l-2 7h4l-2 7"/>
                        </svg>
                        <?= $lang === 'fa' ? 'داناپی' : 'Danapey' ?>
                    </h1>
        
                
                <div>
                    <select onchange="location.href='?lang='+this.value">
                        <option value="fa" <?= $lang === 'fa' ? 'selected' : '' ?>>فارسی</option>
                        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                    <?php if ($isLoggedIn): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7f8c8d" stroke-width="2" style="vertical-align: middle; margin-<?= $lang === 'fa' ? 'right' : 'left' ?>: 10px; cursor: pointer;" onclick="logout()">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                        </svg>
                    <?php endif; ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#21b0b0" stroke-width="2" style="vertical-align: middle; cursor: pointer;" onclick="toggleTheme()">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </div>
                
            </div>
            <div class="token-box">
<?= $lang === 'fa' ? "نشانه‌های یادگیری‌شده: $tokenCount" : "Learned Tokens: $tokenCount" ?></div>
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
    </div>
    <div class="chat-section">
        <div class="container">
            <div class="chat-box">
                <input id="queryInput" type="text" placeholder="<?= $lang === 'fa' ? 'سوال خود را بپرسید' : 'Ask your question' ?>">
                <button class="send-btn" onclick="getResponse()">
                    <svg width="30" height="15" viewBox="0 0 24 24" fill="none" stroke="#80ffff" stroke-width="2">
                        <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                    </svg>
                </button>
            </div>
            <div id="response" class="response"><?= $lang === 'fa' ? 'پاسخ اینجا ظاهر می‌شود' : 'Response will appear here' ?></div>
        </div>
    </div>
    <div id="loginModal" class="modal" onclick="closeModal(event)">
        <div class="modal-content">
            <h2><?= $lang === 'fa' ? 'ورود' : 'Login' ?></h2>
            <input id="username" type="text" placeholder="<?= $lang === 'fa' ? 'نام کاربری' : 'Username' ?>">
            <input id="password" type="password" placeholder="<?= $lang === 'fa' ? 'رمز عبور' : 'Password' ?>">
            <button onclick="login()"><?= $lang === 'fa' ? 'ورود' : 'Login' ?></button>
            <?php if ($isLoggedIn): ?>
                <h2><?= $lang === 'fa' ? 'تغییر رمز' : 'Change Password' ?></h2>
                <input id="newPass" type="password" placeholder="<?= $lang === 'fa' ? 'رمز جدید' : 'New Password' ?>">
                <button onclick="changePass()"><?= $lang === 'fa' ? 'تغییر' : 'Change' ?></button>
            <?php endif; ?>
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
            })
            .then(response => response.text())
            .then(data => {
                if (data === 'success') location.reload();
                else alert('<?= $lang === 'fa' ? 'ورود ناموفق!' : 'Login failed!' ?>');
            });
        }
        function logout() {
            fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'logout=true' })
            .then(response => response.text())
            .then(data => { if (data === 'success') location.reload(); });
        }
        function changePass() {
            const newPass = document.getElementById('newPass').value;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'changePass=true&newPass=' + encodeURIComponent(newPass)
            })
            .then(response => response.text())
            .then(data => {
                if (data === 'success') alert('<?= $lang === 'fa' ? 'رمز تغییر کرد!' : 'Password changed!' ?>');
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
            })
            .then(response => response.text())
            .then(data => {
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
            })
            .then(response => response.text())
            .then(data => responseDiv.innerHTML = data);
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
            })
            .then(response => response.text())
            .then(data => responseDiv.innerHTML = data);
        }
        function submitFeedback(knowledgeId, rating) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'feedback=true&knowledgeId=' + knowledgeId + '&rating=' + rating
            })
            .then(response => response.text())
            .then(data => {
                if (data === 'success') alert('<?= $lang === 'fa' ? 'نظر شما ثبت شد!' : 'Feedback submitted!' ?>');
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
            })
            .then(response => response.text())
            .then(data => {
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
        }
        if (localStorage.getItem('theme') === 'light') document.body.classList.add('light');
    </script>
</body>
</html>