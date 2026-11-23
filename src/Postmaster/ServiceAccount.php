<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Postmaster;

use Illuminate\Support\Facades\Http;
use JsonException;
use OpenSSLAsymmetricKey;
use SensitiveParameter;
use Throwable;

/**
 * A Google service account signing in to Postmaster Tools. Its JSON key
 * file (base64-encoded, or the JSON itself) signs a JWT with RS256 through
 * ext-openssl, with no Google SDK, asking only for the read-only
 * postmaster.traffic.readonly scope (never postmaster.domain, which also
 * creates and deletes domains); Google's token endpoint trades it for an
 * access token. The assertion goes only to TOKEN_URL, whatever token_uri
 * the key file names.
 *
 * The key, the signed assertion and the token never reach a message, a log
 * or an exception's chain: a failure names a status and Google's error
 * code, never the request or Google's words; every parameter holding one is
 * a SensitiveParameter, so a stack trace shows none; and the key is kept
 * only as OpenSSL's opaque key object, which prints nothing.
 */
class ServiceAccount
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const SCOPE = 'https://www.googleapis.com/auth/postmaster.traffic.readonly';

    /** Seconds before a request to Google gives up. */
    public const TIMEOUT = 10;

    /** How long the signed assertion is valid, in seconds (Google's most). */
    protected const LIFETIME = 3600;

    protected function __construct(public readonly string $email, protected readonly OpenSSLAsymmetricKey $key) {}

    /**
     * The service account in a key file, base64-encoded or as JSON.
     *
     * @throws PostmasterException
     */
    public static function fromCredentials(#[SensitiveParameter] string $credentials): self
    {
        $credentials = trim($credentials);
        $json = str_starts_with($credentials, '{') ? $credentials : base64_decode($credentials, true);

        if ($json === false) {
            throw new PostmasterException('campaigns.postmaster.credentials isn\'t base64: base64-encode the service account\'s JSON key file.');
        }

        try {
            // A JSON error's message never quotes the text, but it isn't
            // needed either.
            $file = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PostmasterException('campaigns.postmaster.credentials doesn\'t hold JSON: base64-encode the service account\'s whole JSON key file.');
        }

        $email = is_array($file) ? ($file['client_email'] ?? null) : null;
        $pem = is_array($file) ? ($file['private_key'] ?? null) : null;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! is_string($pem)) {
            throw new PostmasterException('campaigns.postmaster.credentials isn\'t a service account key: its JSON needs client_email and private_key.');
        }

        $key = openssl_pkey_get_private($pem);
        $rsa = $key !== false && (openssl_pkey_get_details($key)['type'] ?? null) === OPENSSL_KEYTYPE_RSA;
        static::clearErrors();

        if ($key === false || ! $rsa) {
            throw new PostmasterException('The private_key in campaigns.postmaster.credentials isn\'t a readable RSA private key.');
        }

        return new self($email, $key);
    }

    /**
     * A fresh access token for the read-only scope. It is never cached: a
     * reading asks for one and drops it.
     *
     * @throws PostmasterException
     */
    public function accessToken(): string
    {
        $now = now()->getTimestamp();
        $unsigned = static::encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.static::encode([
            'iss' => $this->email,
            'scope' => static::SCOPE,
            'aud' => static::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + static::LIFETIME,
        ]);

        $signed = openssl_sign($unsigned, $signature, $this->key, OPENSSL_ALGO_SHA256);
        static::clearErrors();

        if (! $signed || ! is_string($signature)) {
            throw new PostmasterException('The service account\'s key couldn\'t sign Google\'s sign-in.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(static::TIMEOUT)
                ->timeout(static::TIMEOUT)
                ->post(static::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                ]);
        } catch (Throwable $exception) {
            throw new PostmasterException('Google\'s sign-in couldn\'t be reached ('.class_basename($exception).').');
        }

        // Decoded here, never through the response's json(), whose flags an
        // app may set to throw on an HTML error page.
        $answer = json_decode($response->body(), true);
        $token = is_array($answer) ? ($answer['access_token'] ?? null) : null;

        if ($response->successful() && is_string($token) && $token !== '') {
            return $token;
        }

        $error = PostmasterException::code(is_array($answer) ? ($answer['error'] ?? null) : null);
        $hint = $error === 'invalid_grant'
            ? ' The key may have been deleted or disabled, or this server\'s clock may be wrong.'
            : '';

        throw new PostmasterException("Google refused the service account's sign-in (HTTP {$response->status()}".($error === null ? '' : ", {$error}").").{$hint}");
    }

    /**
     * Only the account's address: the key never prints, even in a dump.
     *
     * @return array{email: string}
     */
    public function __debugInfo(): array
    {
        return ['email' => $this->email];
    }

    /**
     * @param  array<string, int|string>  $value
     */
    protected static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($value, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /**
     * Empties OpenSSL's error queue, so a failure here never surfaces in a
     * later caller's openssl_error_string().
     */
    protected static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drained
        }
    }
}
