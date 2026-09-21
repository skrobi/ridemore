<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            line-height: 1.6; 
            color: #1a1a1a;
            background: #ffffff;
            margin: 0;
            padding: 0;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            padding: 40px 20px;
        }
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo img {
            width: 80px;
            height: 80px;
        }
        .content {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 32px;
            margin-bottom: 24px;
        }
        h1 {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0 0 16px 0;
            text-align: center;
        }
        .motto {
            text-align: center;
            font-size: 14px;
            color: #666;
            margin-bottom: 24px;
            font-style: italic;
        }
        p {
            color: #333;
            font-size: 15px;
            margin: 0 0 16px 0;
        }
        .button-wrapper {
            text-align: center;
            margin: 32px 0;
        }
        .button { 
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: #1ea3b3;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 16px;
            transition: background 0.2s;
        }
        .button:hover {
            background: #1a8d9b;
        }
        .button img {
            width: 18px;
            height: 18px;
        }
        .info {
            font-size: 13px;
            color: #666;
            background: #fff;
            border-left: 3px solid #1ea3b3;
            padding: 12px 16px;
            border-radius: 4px;
        }
        .footer { 
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
        }
        .footer p {
            font-size: 13px;
            color: #666;
            margin: 4px 0;
        }
        .footer a {
            color: #1ea3b3;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Logo -->
        <div class="logo">
            <img src="<?= APP_URL ?>/android-chrome-192x192.png" alt="RideMore">
            <p class="motto">Wystarczy że jedziesz</p>
        </div>

        <!-- Content -->
        <div class="content">
            <h2>Zaloguj się do RideMore.bike</h2>
            
            
            <p>Kliknij przycisk poniżej, aby się zalogować:</p>
            
            <div class="button-wrapper">
                <a href="<?= $magic_link ?>" class="button">
                    <img src="<?= APP_URL ?>/assets/icons/mail.svg" alt="">
                    Zaloguj się
                </a>
            </div>
            
            <div class="info">
                <strong>Ważne:</strong> Link wygasa za <?= $expires_minutes ?> minut.<br>
                Jeśli nie próbowałeś się logować, zignoruj tę wiadomość.
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><strong>RideMore</strong></p>
            <p><a href="<?= APP_URL ?>"><?= APP_URL ?></a></p>
        </div>
    </div>
</body>
</html>