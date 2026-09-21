<?php
// core/Utils/OAuthProvider.php
// Fabryka providerów OAuth (Google/Strava) na bazie league/oauth2-client
// GenericProvider — bez dodatkowych paczek per-provider. Endpointy i scope'y
// zaszyte tutaj; clientId/secret z APP_CONFIG['oauth'], redirectUri z app_url.
namespace Utils;

use League\OAuth2\Client\Provider\GenericProvider;

class OAuthProvider
{
    // Metadane providerów. responseResourceOwnerId = pole ze stałym id usera
    // ('sub' u Google, 'id' atlety u Stravy). scopeSeparator: Google wymaga
    // spacji, Strava przecinka.
    private const META = [
        'google' => [
            'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
            'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
            'urlResourceOwnerDetails' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes'                  => ['openid', 'email', 'profile'],
            'scopeSeparator'          => ' ',
            'responseResourceOwnerId' => 'sub',
        ],
        'strava' => [
            'urlAuthorize'            => 'https://www.strava.com/oauth/authorize',
            'urlAccessToken'          => 'https://www.strava.com/oauth/token',
            'urlResourceOwnerDetails' => 'https://www.strava.com/api/v3/athlete',
            'scopes'                  => ['read'],
            'scopeSeparator'          => ',',
            'responseResourceOwnerId' => 'id',
        ],
    ];

    public static function isKnown(string $provider): bool
    {
        return isset(self::META[$provider]);
    }

    // Provider "skonfigurowany" = ma clientId, clientSecret i nie jest jawnie
    // wyłączony ('enabled' => false). Nieskonfigurowany/wyłączony = przycisk się
    // nie pokazuje, redirect zwraca 400. 'enabled' domyślnie true (brak klucza).
    public static function isConfigured(string $provider): bool
    {
        $c = APP_CONFIG['oauth'][$provider] ?? null;
        return is_array($c)
            && ($c['enabled'] ?? true)
            && !empty($c['clientId'])
            && !empty($c['clientSecret']);
    }

    public static function make(string $provider): GenericProvider
    {
        if (!self::isKnown($provider)) {
            throw new \InvalidArgumentException("Nieznany provider OAuth: $provider");
        }
        $cfg  = APP_CONFIG['oauth'][$provider];
        $meta = self::META[$provider];

        return new GenericProvider([
            'clientId'                => $cfg['clientId'],
            'clientSecret'            => $cfg['clientSecret'],
            'redirectUri'             => rtrim(APP_CONFIG['app_url'], '/') . '/auth/' . $provider . '/callback',
            'urlAuthorize'            => $meta['urlAuthorize'],
            'urlAccessToken'          => $meta['urlAccessToken'],
            'urlResourceOwnerDetails' => $meta['urlResourceOwnerDetails'],
            'scopes'                  => $meta['scopes'],
            'scopeSeparator'          => $meta['scopeSeparator'],
            'responseResourceOwnerId' => $meta['responseResourceOwnerId'],
        ]);
    }
}
