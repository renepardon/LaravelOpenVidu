<?php

namespace SquareetLabs\LaravelOpenVidu;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use SquareetLabs\LaravelOpenVidu\Builders\RecordingBuilder;
use SquareetLabs\LaravelOpenVidu\Enums\Uri;
use SquareetLabs\LaravelOpenVidu\Events\SessionDeleted;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduRecordingNotFoundException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduRecordingResolutionException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduRecordingStatusException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduServerRecordingIsDisabledException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduSessionCantRecordingException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduSessionHasNotConnectedParticipantsException;
use SquareetLabs\LaravelOpenVidu\Exceptions\OpenViduSessionNotFoundException;

/**
 * Class OpenVidu
 * @package App\SquareetLabs\LaravelOpenVidu
 */
class OpenVidu
{

    /**
     * @var
     */
    private $config;
    /**
     * Array of active sessions. **This value will remain unchanged since the last time method [[LaravelOpenVidu.fetch]]
     * was called**. Exceptions to this rule are:
     *
     * - {@see Session::fetch} updates that specific Session status
     * - {@see Session::close} automatically removes the Session from the list of active Sessions
     * - {@see Session::forceDisconnect} automatically updates the inner affected connections for that specific Session
     * - {@see Session::forceUnpublish} also automatically updates the inner affected connections for that specific Session
     * - {@see OpenVidu::startRecording} and {@see OpenVidu::stopRecording} automatically updates the recording status of the Session ({@see Session.recording})
     *
     * To get the array of active sessions with their current actual value, you must {@see OpenVidu::fetch} before consulting
     * property {@see activeSessions}
     */
    private $activeSessions = [];

    /**
     * SmsUp constructor.
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param SessionProperties|null $properties
     * @return Session
     * @throws Exceptions\OpenViduSessionCantCreateException
     * @throws Exceptions\OpenViduException
     */
    public function createSession(?SessionProperties $properties = null): Session
    {
        $session = new Session($this->client(), $properties);
        $this->activeSessions[$session->getSessionId()] = $session;
        return $session;
    }

    /**
     * @return Client
     */
    private function client(): Client
    {
        $client = new Client([
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'auth' => [
                $this->config['app'], $this->config['secret']
            ],
            'base_uri' => $this->config['domain'] . ':' . $this->config['port'],
            'debug' => $this->config['debug'],
            'http_errors' => false,
            'verify' => false
        ]);
        return $client;
    }

    /**
     * Starts the recording of a {@see Session}
     * @param RecordingProperties|null $properties
     * @return Recording
     * @throws OpenViduException
     * @throws OpenViduRecordingResolutionException
     * @throws OpenViduServerRecordingIsDisabledException
     * @throws OpenViduSessionCantRecordingException
     * @throws OpenViduSessionHasNotConnectedParticipantsException
     * @throws OpenViduSessionNotFoundException
     */
    public function startRecording(?RecordingProperties $properties = null): Recording
    {
        $recording = null;
        $response = $this->client()->post(Uri::RECORDINGS_START, [
            RequestOptions::JSON => $properties->toArray()
        ]);
        switch ($response->getStatusCode()) {
            case 200:
                $recording = RecordingBuilder::build(json_decode($response->getBody()->getContents()));
                $activeSession = $this->getSession($recording->getSessionId());
                if ($activeSession != null) {
                    $activeSession->setIsBeingRecorded(true);
                }
                return $recording;
            case 404:
                throw new OpenViduSessionNotFoundException();
            case 406:
                throw new OpenViduSessionHasNotConnectedParticipantsException();
            case 409:
                throw new OpenViduSessionCantRecordingException(__('The session is not configured for using media routed or it is already being recorded'));
            case 422:
                throw new OpenViduRecordingResolutionException();
            case 501:
                throw new OpenViduServerRecordingIsDisabledException();
            default:
                $result = json_decode($response->getBody()->getContents());
                if ($result && isset($result['message'])) {
                    throw new OpenViduException($result['message'], $response->getStatusCode());
                }
                throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }

    /**
     * Gets an existing {@see Session}
     * @param string $sessionId
     * @return Session
     * @throws OpenViduSessionNotFoundException
     */
    public function getSession(string $sessionId): Session
    {
        if (array_key_exists($sessionId, $this->activeSessions)) {
            return $this->activeSessions[$sessionId];
        }
        throw new OpenViduSessionNotFoundException();
    }

    /**
     * Stops the recording of a {@see Session}
     * @param string $recordingId The `id` property of the {@see Recording} you want to stop
     * @return Recording
     * @throws OpenViduException
     * @throws OpenViduRecordingNotFoundException
     * @throws OpenViduRecordingStatusException
     * @throws OpenViduSessionNotFoundException
     */
    public function stopRecording(string $recordingId): Recording
    {
        $response = $this->client()->post(Uri::RECORDINGS_STOP . '/' . $recordingId);
        switch ($response->getStatusCode()) {
            case 200:
                $recording = RecordingBuilder::build(json_decode($response->getBody()->getContents()));
                $activeSession = $this->getSession($recording->getSessionId());
                if ($activeSession != null) {
                    $activeSession->setIsBeingRecorded(false);
                }
                return $recording;
            case 404:
                throw new OpenViduRecordingNotFoundException();
            case 406:
                throw new OpenViduRecordingStatusException(__('The recording has `starting` status. Wait until `started` status before stopping the recording.'));
            default:
                $result = json_decode($response->getBody()->getContents());
                if ($result && isset($result['message'])) {
                    throw new OpenViduException($result['message'], $response->getStatusCode());
                }
                throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }

    /**
     * Gets an existing {@see Recording}
     * @param string $recordingId The `id` property of the {@see Recording} you want to retrieve
     * @return string
     * @throws OpenViduException
     * @throws OpenViduRecordingNotFoundException
     */
    public function getRecording(string $recordingId): string
    {
        $response = $this->client()->get(Uri::RECORDINGS_URI . '/' . $recordingId);
        switch ($response->getStatusCode()) {
            case 200:
                $recording = RecordingBuilder::build(json_decode($response->getBody()->getContents()));
                return $recording;
            case 404:
                throw new OpenViduRecordingNotFoundException();
            default:
                $result = json_decode($response->getBody()->getContents());
                if ($result && isset($result['message'])) {
                    throw new OpenViduException($result['message'], $response->getStatusCode());
                }
                throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }

    /**
     * Gets an array with all existing recordings
     * @return array
     * @throws OpenViduException
     */
    public function getRecordings(): array
    {
        $recordings = [];
        $response = $this->client()->get(Uri::RECORDINGS_URI);
        switch ($response->getStatusCode()) {
            case 200:
                $items = json_decode($response->getBody()->getContents());
                foreach ($items as $item) {
                    $recordings[] = RecordingBuilder::build($item);
                }
                return $recordings;
            default:
                $result = json_decode($response->getBody()->getContents());
                if ($result && isset($result['message'])) {
                    throw new OpenViduException($result['message'], $response->getStatusCode());
                }
                throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }


    /**
     * Deletes a {@see Recording}. The recording must have status `stopped`, `ready` or `failed`
     * @param string $recordingId The `id` property of the {@see Recording} you want to delete
     * @return bool
     * @throws OpenViduException
     * @throws OpenViduRecordingNotFoundException
     * @throws OpenViduRecordingStatusException
     */
    public function deleteRecording(string $recordingId): bool
    {
        $response = $this->client()->delete(Uri::RECORDINGS_URI . '/' . $recordingId);

        switch ($response->getStatusCode()) {
            case 200:
                return true;
            case 404:
                throw new OpenViduRecordingNotFoundException();
            case 409:
                throw new OpenViduRecordingStatusException(__('The recording has `started` status. Stop it before deletion'));
            default:
                $result = json_decode($response->getBody()->getContents());
                if ($result && isset($result['message'])) {
                    throw new OpenViduException($result['message'], $response->getStatusCode());
                }
                throw new OpenViduException("Invalid response status code " . $response->getStatusCode(), $response->getStatusCode());
        }
    }

    /**
     * Returns the list of active sessions. <strong>This value will remain unchanged
     * since the last time method {@link SquareetLabs\LaravelOpenVidu#fetch()}
     * was called</strong>. Exceptions to this rule are:
     * <ul>
     * <li>Calling {@see Session::fetch} updates that
     * specific Session status</li>
     * <li>Calling {@see Session::close()} automatically
     * removes the Session from the list of active Sessions</li>
     * <li>Calling
     * {@see Session::forceDisconnect(string)}
     * automatically updates the inner affected connections for that specific
     * Session</li>
     * <li>Calling {@see Session::forceUnpublish(string)}
     * also automatically updates the inner affected connections for that specific
     * Session</li>
     * <li>Calling {@see OpenVidu::startRecording(string)}
     * and {@see LaravelOpenVidu::stopRecording(string)}
     * automatically updates the recording status of the Session
     * ({@see Session::isBeingRecorded()})</li>
     * </ul>
     * <br>
     * To get the list of active sessions with their current actual value, you must
     * call first {@see OpenVidu::fetch()} and then
     * {@see OpenVidu::getActiveSessions()}
     */
    public function getActiveSessions(): array
    {
        return array_values($this->activeSessions);
    }

    /**
     * Handle the event.
     * @param SessionDeleted $event
     */
    public function handle(SessionDeleted $event)
    {
        unset($this->activeSessions[$event->sessionId]);
    }
}
