<?php

namespace Ytmusicapi;

use Illuminate\Support\Facades\Http;

/**
 * Base class representation of the YouTubeMusicAPI OAuth Credentials
 */
class Credentials
{
    /**
     * @var string
     */
    public $client_id;

    /**
     * @var string
     */
    public $client_secret;

    public function __construct($client_id, $client_secret)
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    /**
     * Method for obtaining a new user auth code. First step of token creation.
     *
     * @return object
     */
    public function get_code()
    {
        throw new \Exception("Not implemented");
    }

    /**
     * Method for verifying user auth code and conversion into a FullTokenDict.
     *
     * @return array
     */
    public function token_from_code($device_code)
    {
        throw new \Exception("Not implemented");
    }

    /**
     * Method for requesting a new access token for a given refresh_token.
     * Token must have been created by the same OAuth client.
     *
     * @return array
     */
    public function refresh_token($refresh_token)
    {
        throw new \Exception("Not implemented");
    }
}

/**
 * Class for handling OAuth credential retrieval and refreshing.
 */
class OAuthCredentials extends Credentials
{
    private $http_client;

    /**
     * @param string $client_id Optional. Set the GoogleAPI client_id used for auth flows.
     *   Requires client_secret also be provided if set.
     * @param string $client_secret Optional. Corresponding secret for provided client_id.
     * @param array $proxies Optional. Proxy configuration.
     */
    public function __construct(
        $client_id = null,
        $client_secret = null,
        $proxies = null
    ) {
        if ($client_id !== null && $client_secret === null) {
            throw new \Exception("OAuthCredential init failure. Provide both client_id and client_secret or neither.");
        }

        $this->client_id = $client_id ?: OAUTH_CLIENT_ID;
        $this->client_secret = $client_secret ?: OAUTH_CLIENT_SECRET;

        // Initialisation du client HTTP
        $this->http_client = Http::timeout(30);

        // Ajout des proxys si nécessaire
        if ($proxies) {
            $this->http_client = $this->http_client->withOptions(['proxy' => $proxies]);
        }
    }

    /**
     * Method for obtaining a new user auth code. First step of token creation.
     *
     * @return object
     */
    public function get_code()
    {
        $response = $this->_send_request(OAUTH_CODE_URL, ["scope" => OAUTH_SCOPE]);
        return json_decode($response->body());
    }

    /**
     * Method for sending POST requests with required client_id and User-Agent modifications
     *
     * @param string $url
     * @param array $data
     * @return \Illuminate\Http\Client\Response
     */
    public function _send_request($url, $data)
    {
        $data = (array)$data;
        $data["client_id"] = $this->client_id;

        $response = $this->http_client->post($url, $data);

        if ($response->status() === 401) {
            $data = $response->json();
            $issue = $data['error'] ?? 'unknown_error';
            if ($issue === "unauthorized_client") {
                throw new UnauthorizedOAuthClient("Token refresh error. Most likely client/token mismatch.");
            } elseif ($issue === "invalid_client") {
                throw new BadOAuthClient(
                    "OAuth client failure. Most likely client_id and client_secret mismatch or "
                    . "YouTubeData API is not enabled."
                );
            } else {
                throw new YTMusicServerError(
                    "OAuth request error. status_code: " . $response->status() . ", url: " . $url . ", content: " . $response->body()
                );
            }
        }

        return $response;
    }

    /**
     * Method for verifying user auth code and conversion into a FullTokenDict.
     */
    public function token_from_code($device_code)
    {
        $response = $this->_send_request(
            OAUTH_TOKEN_URL,
            [
                "client_secret" => $this->client_secret,
                "grant_type" => "http://oauth.net/grant_type/device/1.0",
                "code" => $device_code
            ]
        );

        return $response->json();
    }

    /**
     * Method for requesting a new access token for a given refresh_token.
     * Token must have been created by the same OAuth client.
     *
     * @param string $refresh_token Corresponding refresh_token for a matching access_token.
     * @return array
     */
    public function refresh_token($refresh_token)
    {
        $response = $this->_send_request(
            OAUTH_TOKEN_URL,
            [
                "client_secret" => $this->client_secret,
                "grant_type" => "refresh_token",
                "refresh_token" => $refresh_token
            ]
        );

        return $response->json();
    }
}
