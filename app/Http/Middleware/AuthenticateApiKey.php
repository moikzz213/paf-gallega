<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a machine caller by key + secret and resolves it to the user the key was issued for,
 * so everything downstream (notably `Invoice::scopeVisibleTo`) behaves exactly as it would for that
 * person. Sessionless: this is for spreadsheets and BI tools, not browsers.
 *
 * Credentials arrive in whichever shape the client can manage:
 *
 *   - `X-Api-Key` / `X-Api-Secret` headers            - preferred
 *   - `Authorization: Bearer <key>:<secret>`          - most HTTP clients
 *   - `?token=<key>:<secret>`                         - one field, for tools that only hold a single
 *                                                       value (Power Query's "Web API" credential
 *                                                       appends exactly one parameter, named by its
 *                                                       `ApiKeyName`)
 *   - `?key=<key>&secret=<secret>`                    - a URL anyone can paste
 *
 * Prefer the headers where the client allows it: a query string is recorded in web-server logs,
 * proxies and browser history, which makes such a URL a shareable credential.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        [$key, $secret] = $this->credentials($request);

        if ($key === '' || $secret === '') {
            abort(401, 'An API key and secret are required.');
        }

        $apiKey = ApiKey::with('user')->where('key', $key)->first();

        // One message for every failure: a caller learns whether their credentials work, not which
        // half was wrong or whether the key exists.
        if (! $apiKey || ! $apiKey->isUsable() || ! $apiKey->secretMatches($secret)) {
            abort(401, 'Invalid API credentials.');
        }

        $apiKey->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();

        $request->setUserResolver(fn () => $apiKey->user);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    /**
     * The key and secret, from whichever transport the client used.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(Request $request): array
    {
        $header = (string) $request->header('X-Api-Key', '');
        if ($header !== '') {
            return [$header, (string) $request->header('X-Api-Secret', '')];
        }

        $bearer = (string) $request->bearerToken();
        if ($bearer !== '') {
            return $this->split($bearer);
        }

        $token = (string) $request->query('token', '');
        if ($token !== '') {
            return $this->split($token);
        }

        return [(string) $request->query('key', ''), (string) $request->query('secret', '')];
    }

    /**
     * Split a single-field credential. The key half never contains a colon (it is generated from
     * Str::random), so the first colon is the separator.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $token): array
    {
        $position = strpos($token, ':');

        if ($position === false) {
            return [$token, ''];
        }

        return [substr($token, 0, $position), substr($token, $position + 1)];
    }
}
