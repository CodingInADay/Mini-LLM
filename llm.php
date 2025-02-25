<?php
header('Content-Type: text/html; charset=UTF-8');

// اتصال به دیتابیس SQLite
$db = new SQLite3('database.sqlite');
$db->exec("CREATE TABLE IF NOT EXISTS knowledge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question TEXT NOT NULL,
    answer TEXT NOT NULL
)");

$lang = isset($_GET['lang']) ? $_GET['lang'] : 'fa';

// تابع تولید پاسخ پیشرفته
function generateResponse($query, $db, $lang) {
    $stmt = $db->prepare("SELECT question, answer FROM knowledge WHERE LOWER(question) LIKE '%' || LOWER(:query) || '%'");
    $stmt->bindValue(':query', $query, SQLITE3_TEXT);
    $result = $stmt->execute();
    $matches = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $matches[] = $row;
    }

    if (!empty($matches)) {
        // انتخاب رندوم از تطابق‌ها
        $randomMatch = $matches[array_rand($matches)];
        return $randomMatch['answer'];
    } else {
        // تولید پاسخ با ترکیب کلمات
        $allAnswers = [];
        $result = $db->query("SELECT answer FROM knowledge");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $allAnswers[] = $row['answer'];
        }

        if (!empty($allAnswers)) {
            $prefixes = [
                'fa' => ['خب، فکر کنم بگم: ', 'شاید این جور باشه: ', 'یه چیزی مثل این: '],
                'en' => ['Well, I’d say: ', 'Maybe something like: ', 'How about this: ']
            ];
            $connectors = [
                'fa' => [' و ', '، بعدش ', ' یا شاید '],
                'en' => [' and ', ', then ', ' or maybe ']
            ];

            $prefix = $prefixes[$lang][array_rand($prefixes[$lang])];
            $answer1 = $allAnswers[array_rand($allAnswers)];
            $answer2 = $allAnswers[array_rand($allAnswers)];
            $connector = $connectors[$lang][array_rand($connectors[$lang])];

            // ترکیب دو جواب با رابط
            return $prefix . $answer1 . $connector . $answer2;
        }
        return $lang === 'fa' ? 'جوابی ندارم، بیشتر یادم بده!' : 'I don’t have an answer, teach me more!';
    }
}

// پردازش درخواست‌ها
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['train'])) {
        $input = trim($_POST['trainInput']);
        if (!empty($input) && strpos($input, '->') !== false) {
            list($question, $answer) = array_map('trim', explode('->', $input, 2));
            $stmt = $db->prepare("INSERT INTO knowledge (question, answer) VALUES (:question, :answer)");
            $stmt->bindValue(':question', $question, SQLITE3_TEXT);
            $stmt->bindValue(':answer', $answer, SQLITE3_TEXT);
            $stmt->execute();
        }
    } elseif (isset($_POST['query'])) {
        $query = trim($_POST['queryInput']);
        echo generateResponse($query, $db, $lang);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced LLM Simulator (PHP)</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f0f0;
            direction: <?= $lang === 'fa' ? 'rtl' : 'ltr' ?>;
            transition: all 0.3s;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1, h2 {
            margin: 0 0 10px;
            color: #333;
        }
        textarea, input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        select {
            padding: 5px;
            margin-bottom: 10px;
        }
        .response {
            margin-top: 10px;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 4px;
        }
        @media (min-width: 600px) {
            .container {
                flex-direction: row;
            }
            .section {
                width: 50%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="section">
            <select id="language" onchange="window.location.href='?lang='+this.value">
                <option value="fa" <?= $lang === 'fa' ? 'selected' : '' ?>>فارسی</option>
                <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
            </select>
            <h1><?= $lang === 'fa' ? 'آموزش مدل' : 'Train the Model' ?></h1>
            <form method="POST">
                <textarea id="trainInput" name="trainInput" rows="4" 
                    placeholder="<?= $lang === 'fa' ? 'سوال و جواب رو اینجا بنویس (مثال: سلام -> چطوری؟)' : 'Write question and answer here (e.g., Hi -> How are you?)' ?>"></textarea>
                <button type="submit" name="train"><?= $lang === 'fa' ? 'آموزش بده' : 'Train' ?></button>
            </form>
        </div>
        <div class="section">
            <h2><?= $lang === 'fa' ? 'پرس‌وجو' : 'Query' ?></h2>
            <input type="text" id="queryInput" 
                placeholder="<?= $lang === 'fa' ? 'سوالت رو بپرس' : 'Ask your question' ?>">
            <button onclick="getResponse()"><?= $lang === 'fa' ? 'بپرس' : 'Ask' ?></button>
            <div id="response" class="response"><?= $lang === 'fa' ? 'پاسخ اینجا ظاهر می‌شه' : 'Response will appear here' ?></div>
        </div>
    </div>

    <script>
        function getResponse() {
            const query = document.getElementById('queryInput').value.trim();
            const responseDiv = document.getElementById('response');
            if (!query) {
                responseDiv.innerText = '<?= $lang === 'fa' ? 'سوال خالیه!' : 'Query is empty!' ?>';
                return;
            }

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'query=true&queryInput=' + encodeURIComponent(query)
            })
            .then(response => response.text())
            .then(data => responseDiv.innerText = data);
        }
    </script>
</body>
</html>