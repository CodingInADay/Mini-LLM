<?php
session_start();

// اتصال به دیتابیس SQLite
$db = new SQLite3('assistant.db');

// ساخت جدول کاربران و دستورات اگه وجود نداشته باشن
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT, is_admin INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS commands (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, url TEXT)");

// ثبت کاربر پیش‌فرض admin اگه وجود نداشته باشه
$adminExists = $db->querySingle("SELECT COUNT(*) FROM users WHERE username = 'admin'");
if ($adminExists == 0) {
    $hashedPassword = password_hash('password', PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username, password, is_admin) VALUES ('admin', '$hashedPassword', 1)");
}

// مدیریت لاگین
$error = '';
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->bindValue(':username', $username);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($result && password_verify($password, $result['password'])) {
        $_SESSION['user_id'] = $result['id'];
        $_SESSION['username'] = $result['username'];
        $_SESSION['is_admin'] = $result['is_admin'];
    } else {
        $error = 'نام کاربری یا رمز عبور اشتباه است!';
    }
}

// مدیریت خروج
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// فقط کاربران لاگین‌شده می‌تونن ادامه بدن
if (!isset($_SESSION['user_id'])) {
    $showLogin = true;
} else {
    $showLogin = false;

    // اضافه کردن دستور جدید
    if (isset($_POST['add_command'])) {
        $title = trim($_POST['cmd_title']);
        $url = trim($_POST['cmd_url']);
        if (!empty($title) && !empty($url)) {
            $userId = $_SESSION['user_id'];
            $stmt = $db->prepare("INSERT INTO commands (user_id, title, url) VALUES (:user_id, :title, :url)");
            $stmt->bindValue(':user_id', $userId);
            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':url', $url);
            $stmt->execute();
            echo "<script>alert('دستور با موفقیت اضافه شد!');</script>";
        } else {
            echo "<script>alert('عنوان و نشانی نباید خالی باشند!');</script>";
        }
    }

    // حذف دستور
    if (isset($_POST['delete_command'])) {
        $cmdId = $_POST['cmd_id'];
        $stmt = $db->prepare("DELETE FROM commands WHERE id = :id AND user_id = :user_id");
        $stmt->bindValue(':id', $cmdId);
        $stmt->bindValue(':user_id', $_SESSION['user_id']);
        if ($_SESSION['is_admin']) {
            $stmt = $db->prepare("DELETE FROM commands WHERE id = :id"); // ادمین می‌تونه هر دستوری رو حذف کنه
            $stmt->bindValue(':id', $cmdId);
        }
        $stmt->execute();
    }

    // اضافه کردن کاربر جدید (فقط ادمین)
    if (isset($_POST['add_user']) && $_SESSION['is_admin']) {
        $newUsername = trim($_POST['new_username']);
        $newPassword = trim($_POST['new_password']);
        if (!empty($newUsername) && !empty($newPassword)) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
            $stmt->bindValue(':username', $newUsername);
            $stmt->bindValue(':password', $hashedPassword);
            $stmt->execute();

            // دستورات پیش‌فرض
            $newUserId = $db->lastInsertRowID();
            $defaultCommands = [
                ['title' => 'باز کردن گوگل', 'url' => 'https://google.com'],
                ['title' => 'ویکی‌پدیا', 'url' => 'https://wikipedia.org']
            ];
            foreach ($defaultCommands as $cmd) {
                $stmt = $db->prepare("INSERT INTO commands (user_id, title, url) VALUES (:user_id, :title, :url)");
                $stmt->bindValue(':user_id', $newUserId);
                $stmt->bindValue(':title', $cmd['title']);
                $stmt->bindValue(':url', $cmd['url']);
                $stmt->execute();
            }
            echo "<script>alert('کاربر جدید با موفقیت اضافه شد!');</script>";
        } else {
            echo "<script>alert('نام کاربری و رمز نباید خالی باشند!');</script>";
        }
    }

    // تغییر رمز
    if (isset($_POST['change_password'])) {
        $newPassword = trim($_POST['new_password']);
        if (!empty($newPassword)) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
            $stmt->bindValue(':password', $hashedPassword);
            $stmt->bindValue(':id', $_SESSION['user_id']);
            $stmt->execute();
            echo "<script>alert('رمز با موفقیت تغییر کرد!');</script>";
        }
    }

    // ویرایش کاربر (تغییر رمز)
    if (isset($_POST['edit_user']) && $_SESSION['is_admin']) {
        $editUserId = $_POST['edit_user_id'];
        $newPassword = trim($_POST['edit_password']);
        if (!empty($newPassword)) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
            $stmt->bindValue(':password', $hashedPassword);
            $stmt->bindValue(':id', $editUserId);
            $stmt->execute();
            echo "<script>alert('رمز کاربر تغییر کرد!');</script>";
        }
    }

    // حذف کاربر
    if (isset($_POST['delete_user']) && $_SESSION['is_admin']) {
        $userId = $_POST['user_id'];
        $stmt = $db->prepare("DELETE FROM users WHERE id = :id AND id != :admin_id");
        $stmt->bindValue(':id', $userId);
        $stmt->bindValue(':admin_id', $_SESSION['user_id']);
        $stmt->execute();
        $stmt = $db->prepare("DELETE FROM commands WHERE user_id = :id");
        $stmt->bindValue(':id', $userId);
        $stmt->execute();
    }
}
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دستیار هوش مصنوعی</title>
    <link href="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/Vazirmatn-font-face.css" rel="stylesheet">
    <link rel="icon" href="https://cdn.sstatic.net/Sites/stackoverflow/Img/favicon.ico" type="image/x-icon">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #8785a2;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" opacity="0.2"><rect width="10" height="10" fill="#d3d3d3"/><rect x="10" y="10" width="10" height="10" fill="#d3d3d3"/></svg>');
            direction: rtl;
            color: #333;
        }
        .container { max-width: 900px; margin: 0 auto; display: flex; flex-direction: column; gap: 10px; }
        .header { background: #fff; padding: 10px 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { font-size: 20px; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); margin: 0; }
        .header .user-info { font-size: 14px; margin-top: 5px; }
        .header .user-info .status { display: inline-block; width: 10px; height: 10px; background: #00cc00; border-radius: 50%; margin-right: 5px; }
        .section { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { margin: 0 0 10px; text-align: center; color: #4a4a4a; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); font-size: 16px; }
        label::after { content: ':'; }
        input { width: 100%; padding: 10px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; font-family: 'Vazirmatn', sans-serif; }
        button { padding: 8px 16px; background-color: #003087; color: white; border: none; border-radius: 4px; cursor: pointer; margin: 5px; text-shadow: 1px 1px 2px #87ceeb; font-weight: bold; font-family: 'Vazirmatn', sans-serif; box-shadow: 1px 1px 3px rgba(0,0,0,0.3); }
        button:hover { background-color: #002357; }
        .button-group { display: flex; justify-content: center; gap: 10px; }
        #guess-output { padding: 10px; background-color: #4a4a4a; border-radius: 4px; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); color: #ffd700; min-height: 20px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: #fff; margin: 10% auto; padding: 15px; width: 80%; max-width: 600px; max-height: 70vh; overflow-y: auto; border-radius: 8px; position: relative; }
        .modal-content table { width: 100%; border-collapse: collapse; }
        .modal-content th, .modal-content td { padding: 10px; border-bottom: 1px solid #ddd; text-align: right; }
        .modal-content input { margin: 10px 0; }
        .close { position: absolute; top: 10px; left: 10px; cursor: pointer; }
        .execute-btn { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap; }
        .login-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); max-width: 400px; margin: 0 auto; text-align: center; }
        .error { color: red; margin: 10px 0; }
        .delete-btn { background: none; border: none; padding: 0; cursor: pointer; }
        .user-actions { display: flex; align-items: center; gap: 5px; }
        .user-actions input[type="password"] { width: 100px; padding: 5px; }
        hr { border: 0; border-top: 1px solid #ddd; margin: 15px 0; }
        @media (min-width: 600px) {
            .container { flex-direction: row; flex-wrap: wrap; }
            .section { width: calc(33.33% - 10px); }
            .header { width: 100%; }
        }
        @media (max-width: 599px) {
            .section, .header { width: calc(100% - 20px); margin: 0 10px; }
            .modal-content { width: calc(100% - 20px); margin: 10% 10px; }
            .execute-btn { flex-direction: row; align-items: center; }
            button { width: auto; }
        }
        [lang="en"] body, [lang="en"] .modal-content th, [lang="en"] .modal-content td { direction: ltr; text-align: left; }
    </style>
</head>
<body onload="document.getElementById('<?php echo $showLogin ? 'login_username' : 'cmd-input'; ?>').focus()">
    <?php if ($showLogin) { ?>
        <div class="login-box">
            <h2>ورود به دستیار هوشمند</h2>
            <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
            <form method="POST">
                <label>نام کاربری</label>
                <input type="text" id="login_username" name="username" required>
                <label>رمز عبور</label>
                <input type="password" name="password" required>
                <button type="submit" name="login">ورود</button>
            </form>
        </div>
    <?php } else { ?>
        <div class="container">
            <div class="header">
                <h1 data-fa="دستیار هوش مصنوعی" data-en="AI Assistant">دستیار هوش مصنوعی</h1>
                <div class="user-info">
                    <span class="status"></span>
                    کاربر فعال: <?php echo $_SESSION['username']; ?>
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="logout" style="padding: 5px; margin: 0 5px;">
                            <svg width="16" height="16" viewBox="0 0 24 24"><path d="M10 9V5l-7 7 7 7v-4h11v-6H10z" fill="white"/></svg>
                        </button>
                    </form>
                </div>
            </div>
            <div class="section">
                <h2 data-fa="اجرای دستور" data-en="Execute Command">اجرای دستور</h2>
                <div class="execute-btn">
                    <input type="text" id="cmd-input" onkeyup="guessCommand()" placeholder-fa="دستور را وارد کنید" placeholder-en="Enter command">
                    <button onclick="executeCommand()">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" fill="white"/></svg>
                    </button>
                </div>
            </div>
            <div class="section">
                <h2 data-fa="حدس دستور" data-en="Guess Command">حدس دستور</h2>
                <div id="guess-output"></div>
            </div>
            <div class="section">
                <h2 data-fa="معرفی دستورها" data-en="Define Commands">معرفی دستورها</h2>
                <label data-fa="عنوان دستور" data-en="Command Title">عنوان دستور</label>
                <input type="text" id="cmd-title" placeholder-fa="مثلاً: باز کردن گوگل" placeholder-en="e.g., Open Google">
                <label data-fa="نشانی دستور" data-en="Command URL">نشانی دستور</label>
                <input type="text" id="cmd-url" placeholder-fa="مثلاً: https://google.com" placeholder-en="e.g., https://google.com">
                <div class="button-group">
                    <form method="POST" style="display:inline;" onsubmit="addCommand(event)">
                        <button type="submit" name="add_command" data-fa="افزودن" data-en="Add">افزودن</button>
                        <input type="hidden" name="cmd_title" id="cmd-title-hidden">
                        <input type="hidden" name="cmd_url" id="cmd-url-hidden">
                    </form>
                    <button onclick="showCommands()" data-fa="نمایش دستورها" data-en="Show Commands">نمایش دستورها</button>
                    <?php if ($_SESSION['is_admin']) { ?>
                        <button onclick="showUsers()" data-fa="مدیریت کاربران" data-en="Manage Users">مدیریت کاربران</button>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div id="commands-modal" class="modal" onclick="closeModal(event, 'commands-modal')">
            <div class="modal-content">
                <span class="close" onclick="closeModal(event, 'commands-modal')">
                    <svg width="20" height="20" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="#333" stroke-width="2"/></svg>
                </span>
                <h2 data-fa="فهرست دستورها" data-en="Command List">فهرست دستورها</h2>
                <?php if ($_SESSION['is_admin']) { ?>
                    <select id="user-filter" onchange="filterCommands()">
                        <option value="">همه کاربران</option>
                        <?php
                        $users = $db->query("SELECT id, username FROM users");
                        while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
                            echo "<option value='{$user['id']}'>{$user['username']}</option>";
                        }
                        ?>
                    </select>
                <?php } ?>
                <table id="cmd-table">
                    <thead>
                        <tr>
                            <th data-fa="عنوان" data-en="Title">عنوان</th>
                            <th data-fa="نشانی" data-en="URL">نشانی</th>
                            <th data-fa="حذف" data-en="Delete">حذف</th>
                        </tr>
                    </thead>
                    <tbody id="cmd-table-body"></tbody>
                </table>
            </div>
        </div>

        <?php if ($_SESSION['is_admin']) { ?>
        <div id="users-modal" class="modal" onclick="closeModal(event, 'users-modal')">
            <div class="modal-content">
                <span class="close" onclick="closeModal(event, 'users-modal')">
                    <svg width="20" height="20" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12" stroke="#333" stroke-width="2"/></svg>
                </span>
                <h2 data-fa="مدیریت کاربران" data-en="Manage Users">مدیریت کاربران</h2>
                
                <form method="POST">
                    <label>نام کاربری جدید</label>
                    <input type="text" name="new_username" required>
                    <label>رمز عبور جدید</label>
                    <input type="password" name="new_password" required>
                    <button type="submit" name="add_user">افزودن کاربر</button>
                </form>
                
                <hr>
                
                <h2>تغییر رمز خود</h2>
                <form method="POST">
                    <label>رمز جدید</label>
                    <input type="password" name="new_password" required>
                    <button type="submit" name="change_password">تغییر رمز</button>
                </form>
                
                <hr>
                
                <h2>کاربران</h2>
                <table>
                    <thead>
                        <tr>
                            <th>نام کاربری</th>
                            <th>اقدامات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $users = $db->query("SELECT id, username FROM users WHERE id != {$_SESSION['user_id']}");
                        while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
                            echo "<tr>
                                <td>{$user['username']}</td>
                                <td>
                                    <div class='user-actions'>
                                        <form method='POST' style='display:inline;'>
                                            <input type='password' name='edit_password' placeholder='رمز جدید'>
                                            <input type='hidden' name='edit_user_id' value='{$user['id']}'>
                                            <button type='submit' name='edit_user'>تغییر</button>
                                        </form>
                                        <form method='POST' style='display:inline;' onsubmit='return confirmDeleteUser()'>
                                            <input type='hidden' name='user_id' value='{$user['id']}'>
                                            <button type='submit' name='delete_user' class='delete-btn'>
                                                <svg width='20' height='20' viewBox='0 0 24 24'>
                                                    <path d='M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z' fill='#333'/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
    <?php } ?>

    <script>
        let commands = <?php
            $query = $_SESSION['is_admin'] ? "SELECT c.*, u.username FROM commands c JOIN users u ON c.user_id = u.id" : "SELECT * FROM commands WHERE user_id = {$_SESSION['user_id']}";
            $result = $db->query($query);
            $cmds = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $cmds[] = $row;
            }
            echo json_encode($cmds);
        ?>;

        function addCommand(event) {
            const title = document.getElementById('cmd-title').value.trim();
            const url = document.getElementById('cmd-url').value.trim();
            if (title && url) {
                document.getElementById('cmd-title-hidden').value = title;
                document.getElementById('cmd-url-hidden').value = url;
                document.getElementById('cmd-title').value = '';
                document.getElementById('cmd-url').value = '';
            } else {
                event.preventDefault();
                alert('عنوان و نشانی نباید خالی باشند!');
            }
        }

        function showCommands() {
            const tbody = document.getElementById('cmd-table-body');
            tbody.innerHTML = '';
            commands.forEach(cmd => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${cmd.title}</td>
                    <td>${cmd.url}</td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirmDeleteCommand()">
                            <input type="hidden" name="cmd_id" value="${cmd.id}">
                            <button type="submit" name="delete_command" class="delete-btn">
                                <svg width="20" height="20" viewBox="0 0 24 24">
                                    <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="#333"/>
                                </svg>
                            </button>
                        </form>
                    </td>
                `;
                tbody.appendChild(row);
            });
            document.getElementById('commands-modal').style.display = 'block';
            filterCommands();
        }

        function filterCommands() {
            const filter = document.getElementById('user-filter') ? document.getElementById('user-filter').value : '';
            const tbody = document.getElementById('cmd-table-body');
            tbody.innerHTML = '';
            commands.filter(cmd => !filter || cmd.user_id == filter).forEach(cmd => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${cmd.title}</td>
                    <td>${cmd.url}</td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirmDeleteCommand()">
                            <input type="hidden" name="cmd_id" value="${cmd.id}">
                            <button type="submit" name="delete_command" class="delete-btn">
                                <svg width="20" height="20" viewBox="0 0 24 24">
                                    <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="#333"/>
                                </svg>
                            </button>
                        </form>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        function showUsers() {
            document.getElementById('users-modal').style.display = 'block';
        }

        function closeModal(event, modalId) {
            const modal = document.getElementById(modalId);
            if (event.target === modal || event.target.closest('.close')) {
                modal.style.display = 'none';
            }
        }

        function confirmDeleteCommand() {
            return confirm('آیا مطمئن هستید که می‌خواهید این دستور را حذف کنید؟');
        }

        function confirmDeleteUser() {
            return confirm('آیا مطمئن هستید که می‌خواهید این کاربر را حذف کنید؟');
        }

        function guessCommand() {
            const input = document.getElementById('cmd-input').value.trim().toLowerCase();
            const output = document.getElementById('guess-output');
            if (!input) {
                output.innerText = '';
                return;
            }
            const scores = commands.filter(cmd => cmd.title && cmd.url).map(cmd => {
                const words = cmd.title.toLowerCase().split(' ');
                const matches = words.filter(word => input.includes(word)).length;
                return { cmd, score: matches / words.length };
            });
            const best = scores.sort((a, b) => b.score - a.score)[0];
            output.innerText = best && best.score > 0 ? best.cmd.title : 'دستوری پیدا نشد!';
        }

        function executeCommand() {
            const input = document.getElementById('cmd-input').value.trim().toLowerCase();
            const scores = commands.filter(cmd => cmd.title && cmd.url).map(cmd => {
                const words = cmd.title.toLowerCase().split(' ');
                const matches = words.filter(word => input.includes(word)).length;
                return { cmd, score: matches / words.length };
            });
            const best = scores.sort((a, b) => b.score - a.score)[0];
            if (best && best.score > 0) {
                window.open(best.cmd.url, '_blank', 'width=800,height=600,menubar=no,toolbar=no');
            } else {
                alert('دستوری پیدا نشد!');
            }
        }
    </script>
</body>
</html>