<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>従業員 新規登録</title>
</head>
<body>
    <h1>従業員 新規登録</h1>

    <form action="register_employee_action.php" method="POST">
        <label>名前：</label>
        <input type="text" name="name" required><br><br>

        <label>メールアドレス：</label>
        <input type="email" name="email" required><br><br>

        <label>パスワード：</label>
        <input type="password" name="password" required><br><br>

        <button type="submit">登録する</button>
    </form>
</body>
</html>
