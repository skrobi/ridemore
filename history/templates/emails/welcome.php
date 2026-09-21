<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .highlight { background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🎉 Witaj w Koronie Rowerowej Polski!</h2>
        
        <p>Cześć <?= $username ?>!</p>
        
        <p>Cieszymy się, że dołączyłeś do naszej społeczności rowerzystów. Przed Tobą:</p>
        
        <div class="highlight">
            <ul>
                <li><strong><?= $total_routes ?> tras</strong> do zaliczenia w całej Polsce</li>
                <li>System <strong>medali i odznak</strong> za postępy</li>
                <li>Automatyczne rozpoznawanie tras z <strong>plików GPX</strong></li>
                <li>Społeczność pasjonatów rowerowych przygód</li>
            </ul>
        </div>
        
        <p>Zacznij już dziś - załaduj swój pierwszy plik GPX lub przeglądaj mapę tras!</p>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="<?= APP_URL ?>" style="display: inline-block; padding: 12px 24px; background: #00d4ff; color: white; text-decoration: none; border-radius: 6px;">Przejdź do aplikacji</a>
        </p>
        
        <div class="footer">
            <p>Miłych przygód na dwóch kółkach! 🚴‍♂️<br>
            Zespół Korony Rowerowej Polski</p>
        </div>
    </div>
</body>
</html>
