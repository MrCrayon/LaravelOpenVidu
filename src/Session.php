<?php

namespace SquareetLabs\LaravelOpenVidu;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Event;
use SquareetLabs\LaravelOpenVidu\Enums\MediaMode;
use SquareetLabs\LaravelOpenVidu\Enums\OpenViduRole;
use SquareetLabs\LaravelOpenVidu\Enums\OutputMode;
use SquareetLabs\LaravelOpenVidu\Enums\RecordingLayout;
use SquareetLabs\LaravelOpenVidu\Enums\RecordingMode;
use SquareetLabs\LaravelOpenVidu\Enums\Uri;
use SquareetLabs\LaravelOpenVidu\Events\SessionDeleted;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduSessionCantCloseException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduSessionCantCreateException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduTokenCantCreateException;

/**
 * Class Session
 * @package SquareetLabs\LaravelOpenVidu
 */
class Session implements \JsonSerializable
{
    /** @var  Client */
    private $client;

    /** @var  string */
    private $sessionId;

    /** @var SessionProperties */
    private $properties;

    /** @var bool */
    private $recording;

    /** @var array */
    private $activeConnections;

    /** @var int */
    private $createdAt;

    /**
     * Session constructor.
     * @param Client $client
     * @param SessionProperties|null $properties
     * @throws OpenViduSessionCantCreateException
     * @throws OpenViduException
     */
    public function __construct(Client $client, ?SessionProperties $properties = null)
    {
        $this->client = $client;
        $this->properties = $properties ? $properties : $this->getDefaultSessionProperties();
        try {
            $this->sessionId = $this->getSessionId();
        } catch (OpenViduSessionCantCreateException $e) {
            throw $e;
        }
    }

    /**
     * @return SessionProperties
     */
    private function getDefaultSessionProperties(): SessionProperties
    {
        return new SessionProperties(MediaMode::ROUTED, RecordingMode::MANUAL, OutputMode::COMPOSED, RecordingLayout::BEST_FIT);
    }

    /**
     * @return string
     * @throws OpenViduException
     */
    public function getSessionId()
    {
        if (!$this->hasSessionId()) {
            $response = $this->client->post(Uri::SESSION_URI, [
                RequestOptions::JSON => $this->properties->toArray()
            ]);
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody()->getContents())->id;
            } else if ($response->getStatusCode() == 409) {
                return $this->properties->getCustomSessionId();
            } else {
                $result = json_decode($response->getBody()->getContents());
                if ($result && isset($result['message'])) {
                    throw new OpenViduException($result['message'], $response->getStatusCode());
                }
                throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
            }
        }
    }

    /**
     * The following values are considered empty:
     * <ul><li>"" (an empty string)</li>
     * <li>0 (0 as an integer)</li>
     * <li>0.0 (0 as a float)</li>
     * <li>"0" (0 as a string)</li>
     * <li>NULL</li>
     * <li>FALSE </li>
     * <li>array() (an empty array)</li></ul>
     * @return bool
     */
    private function hasSessionId(): bool
    {
        return !empty($this->sessionId);
    }

    /**
     ** Gets a new token associated to Session object with default values for
     * {@see TokenOptions}. This always translates into a
     * new request to OpenVidu Server
     *
     * @param TokenOptions|null $tokenOptions
     * @return string The generated token
     * @throws OpenViduException
     */
    public function generateToken(?TokenOptions $tokenOptions = null)
    {
        if (!$this->hasSessionId()) {
            $this->getSessionId();
        }
        try {
            if (!$tokenOptions) {
                $tokenOptions = $this->getDefaultTokenOptions();
            }
            $response = $this->client->post(Uri::TOKEN_URI, [
                RequestOptions::JSON => array_merge($tokenOptions->toArray(), ['session' => $this->sessionId])
            ]);
            return json_decode($response->getBody()->getContents());
        } catch (Exception $e) {
            throw new OpenViduTokenCantCreateException($e->getMessage(), $e);
        }
    }

    /**
     * @return TokenOptions
     */
    private function getDefaultTokenOptions(): TokenOptions
    {
        return new TokenOptions(OpenViduRole::PUBLISHER);
    }

    /**
     * Gracefully closes the Session: unpublishes all streams and evicts every
     * participant
     * @throws OpenViduSessionCantCloseException
     */
    public function close()
    {
        try {
            $response = $this->client->delete(Uri::SESSION_URI . '/' . $this->sessionId);
            if ($response->getStatusCode() === 200) {
                Event::dispatch(new SessionDeleted($this->sessionId));
            }
        } catch (Exception $e) {
            throw new OpenViduSessionCantCloseException("Could not close session", $e);
        }
    }

    /**
     * Updates every property of the Session with the current status it has in
     * OpenVidu Server. This is especially useful for getting the list of active
     * connections to the Session
     * ({@see getActiveConnections()}) and use
     * those values to call
     * {@see forceDisconnect(Connection)} or
     * {@link forceUnpublish(Publisher)}. <br>
     *
     * To update every Session object owned by OpenVidu object, call
     * {@see fetch()}
     *
     * @return true if the Session status has changed with respect to the server,
     * false if not. This applies to any property or sub-property of the object
     */

    public function fetch()
    {
        $response = $this->client->get(Uri::SESSION_URI . '/' . $this->sessionId, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);
        if ($response->getStatusCode() === 200) {
            $beforeJSON = $this->toJson();
            $this->resetSessionWithJson($response->getBody()->getContents());
            $afterJSON = $this->toJson();
            return ($beforeJSON !== $afterJSON);
        }
    }


    /**
     * Forces the user with Connection `connectionId` to leave the session. OpenVidu Browser will trigger the proper events on the client-side
     * (`streamDestroyed`, `connectionDestroyed`, `sessionDisconnected`) with reason set to `"forceDisconnectByServer"`
     *
     * You can get `connection` parameter from activeConnections array {@see Connection::getConnectionId()} for getting each `connectionId` property).
     * Remember to call {@see fetch()} before to fetch the current actual properties of the Session from OpenVidu Server
     *
     * @param string $connectionId
     * @return bool
     * @throws OpenViduException
     */
    public function forceDisconnect(string $connectionId): bool
    {
        $response = $this->client->delete(Uri::SESSION_URI . '/' . $this->sessionId . '/connection/' . $connectionId, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);
        if ($response->getStatusCode() === 204) {
            $connectionClosed = null;
            $this->activeConnections = array_filter($this->activeConnections, function (Connection $connection) use ($connectionId) {
                if ($connection->getConnectionId() !== $connectionId) {
                    return true;
                } else {
                    $connectionClosed = $connection;
                    return false;

                }
            });
            if ($connectionClosed != null) {
                foreach ($connectionClosed->publishers as $publisher) {
                    foreach ($this->activeConnections as $con) {
                        $con->subscribers = array_filter($con->subscribers, function ($subscriber) use ($publisher) {
                            if (is_array($subscriber) && array_key_exists('streamId', $subscriber)) {
                                // Subscriber with advanced webRtc configuration properties
                                return $subscriber['streamId'] !== $publisher->streamId;
                            } else {
                                // Regular string subscribers
                                return $subscriber !== $publisher->streamId;
                            }
                        });
                    }
                }
            }
            return true;
        } else {
            $result = json_decode($response->getBody()->getContents());
            if ($result && isset($result['message'])) {
                throw new OpenViduException($result['message'], $response->getStatusCode());
            }
            throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }

    /**
     * Forces some user to unpublish a Stream. OpenVidu Browser will trigger the
     * proper events on the client-side (<code>streamDestroyed</code>) with reason
     * set to "forceUnpublishByServer". <br>
     *
     * You can get <code>streamId</code> parameter with
     * {@see Session::getActiveConnections()} and then for
     * each Connection you can call
     * {@see  Connection::getPublishers()}. Finally
     * {@see Publisher::getStreamId()}) will give you the
     * <code>streamId</code>. Remember to call
     * {@see fetch()} before to fetch the current
     * actual properties of the Session from OpenVidu Server
     *
     * @param string $streamId
     * @return bool
     * @throws OpenViduException
     */
    public function forceUnpublish(string $streamId)
    {
        $response = $this->client->delete(Uri::SESSION_URI . '/' . $this->sessionId . '/stream/' . $streamId, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]
        ]);

        if ($response->getStatusCode() === 204) {
            foreach ($this->activeConnections as $connection) {
                $connection->publishers = array_filter($connection->publishers, function (array $publisher) use ($streamId) {
                    return $streamId !== $publisher->streamId;
                });

                if ($connection->subscribers && count($connection->subscribers) > 0) {
                    $connection->subscribers = array_filter($connection->subscribers, function (array $subscriber) use ($streamId) {
                        if (array_key_exists('streamId', $subscriber)) {
                            // Subscriber with advanced webRtc configuration properties
                            return $subscriber['streamId'] !== $streamId;
                        } else {
                            // Regular string subscribers
                            return $subscriber !== $streamId;
                        }
                    });
                }
            }
            return true;
        } else {
            $result = json_decode($response->getBody()->getContents());
            if ($result && isset($result['message'])) {
                throw new OpenViduException($result['message'], $response->getStatusCode());
            }
            throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }

    /**
     * Returns the list of active connections to the session. <strong>This value
     * will remain unchanged since the last time method
     * {@see fetch()} was called</strong>.
     * Exceptions to this rule are:
     * <ul>
     * <li>Calling {@link SquareetLabs\LaravelOpenVidu\Session#forceUnpublish(String)}
     * updates each affected Connection status</li>
     * <li>Calling {@link SquareetLabs\LaravelOpenVidu\Session#forceDisconnect(String)}
     * updates each affected Connection status</li>
     * </ul>
     * <br>
     * To get the list of active connections with their current actual value, you
     * must call first {@link SquareetLabs\LaravelOpenVidu\Session#fetch()} and then
     * {@link SquareetLabs\LaravelOpenVidu\Session#f#getActiveConnections()}
     */
    public function getActiveConnections(): array
    {
        return $this->activeConnections;
    }

    /**
     * Returns the properties defining the session
     */
    public function getProperties(): SessionProperties
    {
        return $this->properties;
    }

    /**
     * Returns whether the session is being recorded or not
     */
    public function isBeingRecorded(): bool
    {
        return $this->recording;
    }

    /**
     * Set value
     * @param bool $recording
     */
    public function setIsBeingRecorded(bool $recording)
    {
        $this->recording = $recording;
    }


    /**
     * Convert the model instance to JSON.
     *
     * @param int $options
     * @return string
     *
     */
    public function toJson($options = 0): string
    {
        $json = json_encode($this->jsonSerialize(), $options);
        return $json;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $array = ['sessionId' => $this->sessionId, 'properties' => $this->properties->toArray(), 'recording' => $this->recording, 'createdAt' => $this->createdAt];
        foreach ($this->activeConnections as $connection) {
            $array['activeConnections'][] = $connection->toArray();
        }

        foreach ($array as $key => $value) {
            if (is_null($value) || $value == '')
                unset($array[$key]);
        }
        return $array;
    }

    /**
     * @param string $json
     */
    protected function resetSessionWithJson(string $json)
    {
        $JSONSession = json_decode($json);
        $this->sessionId = $JSONSession->id;
        $this->createdAt = $JSONSession->created_at;
        $this->recording = $JSONSession->recording;

        if ($this->properties) {
            $this->properties = new SessionProperties($JSONSession->mediaMode, $JSONSession->recordingMode, $JSONSession->defaultOutputMode, $JSONSession->defaultRecordingLayout,
                $this->properties->getCustomSessionId() ? $this->properties->getCustomSessionId() : $JSONSession->customSessionId,
                $JSONSession->defaultCustomLayout ? $JSONSession->defaultCustomLayout : $this->properties->getDefaultCustomLayout());
        } else {
            $this->properties = new SessionProperties($JSONSession->mediaMode, $JSONSession->recordingMode, $JSONSession->defaultOutputMode, $JSONSession->defaultRecordingLayout, $JSONSession->customSessionId, $JSONSession->defaultCustomLayout);
        }

        $this->activeConnections = [];

        foreach ($JSONSession->connections as $connection) {
            $content = $connection->content;
            $publishers = [];
            foreach ($content->publishers as $publisher) {
                $publishers[] = new Publisher($publisher->streamId, $publisher->createdAt, $publisher->hasAudio, $publisher->hasVideo, $publisher->audioActive, $publisher->videoActive, $publisher->frameRate, $publisher->typeOfVideo, $publisher->videoDimensions);
            }
            $subscribers = [];
            foreach ($content->subscribers as $subscriber) {
                $subscribers[] = $subscriber->streamId;
            }
            $this->activeConnections[] = new Connection($content->connectionId, $content->createdAt, $content->role, $content->token, $content->location, $content->platform, $content->serverData, $content->clientData, $publishers, $subscribers);
        }
        usort($this->activeConnections[], function ($a, $b) {
            ($a->createdAt > $b->createdAt) ? 1 : ($b->createdAt > $a->createdAt) ? -1 : 0;
        });
    }

    /**
     * @param Session $other
     * @return bool
     */
    protected function equalTo(Session $other)
    {
        $equals = (
            $this->createdAt === $other->createdAt &&
            $this->createdAt === $other->createdAt &&
            $this->recording === $other->recording &&
            count($this->activeConnections) === count($other->activeConnections) &&
            $this->properties->toJson() === $other->properties->toJson()
        );
        if ($equals) {
            $i = 0;
            while ($equals && $i < count($this->activeConnections)) {
                $equals = $this->activeConnections[$i]->equalTo($other->activeConnections[$i]);
                $i++;
            }
            return $equals;
        } else {
            return false;
        }
    }

    /**
     * @return string
     * @throws OpenViduSessionCantCreateException
     * @throws GuzzleException
     * @throws OpenViduException
     */
    public function __toString(): string
    {
        return $this->getSessionId();
    }
}
