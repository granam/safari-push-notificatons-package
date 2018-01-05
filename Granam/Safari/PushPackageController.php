<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\Safari;

use Granam\Strict\Object\StrictObject;

abstract class PushPackageController extends StrictObject
{
    /**
     * @var PushPackage
     */
    private $pushPackage;

    public function __construct(PushPackage $pushPackage)
    {
        $this->pushPackage = $pushPackage;
    }

    /**
     * The URL format should be {webServiceURL}/{version}/pushPackages/{websitePushID}
     *
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW24
     * @throws \Granam\Safari\Exceptions\CanNotCreateTemporaryPackageDir
     * @throws \Granam\Safari\Exceptions\CanNotEncodeWebsiteToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveWebsiteJsonToPackage
     * @throws \Granam\Safari\Exceptions\CanNotCreateZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotAddFileToZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCloseZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCreateDirForIconSet
     * @throws \Granam\Safari\Exceptions\CanNotCopyIcon
     * @throws \Granam\Safari\Exceptions\CanNotCalculateSha1FromFile
     * @throws \Granam\Safari\Exceptions\CanNotEncodeManifestDataToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveManifestJsonFile
     * @throws \Granam\Safari\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Safari\Exceptions\CanNotReadCertificateData
     * @throws \Granam\Safari\Exceptions\CanNotGetResourceFromOpenedCertificate
     * @throws \Granam\Safari\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     * @throws \Granam\Safari\Exceptions\CanNotSignManifest
     * @throws \Granam\Safari\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Safari\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Safari\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Safari\Exceptions\CanNotSaveDerSignatureToFile
     */
    public function pushPackages()
    {
        $this->sendAlreadyExpiredHeaders();
        $path = $_SERVER['PATH_INFO'] ?? '';
        if (!\preg_match('~^.*/v(?<version>\d+)/pushPackages/(?<websitePushId>[^/]+)~', $path, $matches)) {
            \header('HTTP/1.0 400 Bad Request');
            exit;
        }
        if (!$this->isWebsitePushIdMatching($matches['websitePushId'])) {
            \header('HTTP/1.1 403 Forbidden Invalid Website Push Id');
            exit;
        }
        $contents = \file_get_contents('php://input');
        if (!$contents) {
            \header('HTTP/1.0 400 Bad Request Missing Parameters');
            exit;
        }
        $contents = \json_decode($contents, true /* to get associative array */);
        $userAuthenticationToken = (string)($contents['id'] ?? '');
        if ($userAuthenticationToken === '') {
            \header('HTTP/1.0 400 Bad Request Missing Parameter id');
            exit;
        }
        $zipPackage = $this->pushPackage->createPushPackage($userAuthenticationToken);
        header('Content-type: application/zip');
        readfile($zipPackage);
        exit;
    }

    private function sendAlreadyExpiredHeaders()
    {
        \header('Cache-Control: no-cache, must-revalidate');
        \header('Expires: Thu, 01 Jan 1970 00:00:01 +0000');
    }

    private function isWebsitePushIdMatching(string $websitePushId): bool
    {
        return $websitePushId !== $this->pushPackage->getWebsitePushId();
    }

    /**
     * This covers both adding a new device as well as deleting old one, depending on request method (POST or DELETE).
     * The URL format should be {webServiceURL}/{version}/devices/{deviceToken}/registrations/{websitePushID}
     *
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW24
     */
    public function devices()
    {
        // this is the authorization key we packaged in the website.json pushPackage
        $userAuthenticationToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($userAuthenticationToken === '') {
            \header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        $path = $_SERVER['PATH_INFO'] ?? '';
        if (!\preg_match('~^.*/v(?<version>\d+)/devices/(?<deviceToken>[^/]+)/registrations/(?<websitePushId>[^/]+)~', $path, $matches)) {
            \header('HTTP/1.0 400 Bad Request');
            exit;
        }
        if (!$this->isWebsitePushIdMatching($matches['websitePushId'])) {
            \header('HTTP/1.1 403 Forbidden Invalid Website Push Id');
            exit;
        }
        $deviceToken = $matches['deviceToken'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->addDevice($userAuthenticationToken, $deviceToken);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->deleteDevice($userAuthenticationToken, $deviceToken);
            exit;
        }
    }

    abstract protected function addDevice(string $userAuthenticationToken, string $token);

    abstract protected function deleteDevice(string $userAuthenticationToken, string $token);

    /**
     * To receive reported errors from Apple.
     * The URL format should be {webServiceURL}/{version}/log and errors should be reported by POST method
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW27
     */
    public function log()
    {
        $contents = \file_get_contents('php://input');
        if (!$contents) {
            \header('HTTP/1.0 400 Bad Request Missing Content');
            exit;
        }
        $decoded = \json_decode($contents, true /* to get associative array */);
        if (!\array_key_exists('log', $decoded)) {
            \header('HTTP/1.0 400 Bad Request Missing log');
            exit;
        }
        $this->processErrorLog((array)$decoded['log']);
    }

    abstract protected function processErrorLog(array $log);

    /**
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW12
     * @throws \Granam\Safari\Exceptions\CanNotEncodePushPayloadToJson
     */
    public function push()
    {
        $title = \trim($_POST['title'] ?? $_GET['title'] ?? '');
        if ($title === '') {
            \header('HTTP/1.0 400 Bad Request Missing Title');
            exit;
        }
        $text = \trim($_POST['text'] ?? $_GET['text'] ?? '');
        if ($text === '') {
            \header('HTTP/1.0 400 Bad Request Missing Text');
            exit;
        }
        $userAuthenticationToken = \trim($_POST['user-authentication-token'] ?? $_GET['user-authentication-token'] ?? '');
        if ($userAuthenticationToken === '') {
            \header('HTTP/1.0 400 Bad Request Missing User Authentication Token');
            exit;
        }
        $urlArguments = $_POST['arguments'] ?? $_GET['arguments'] ?? [];
        if (\is_string($urlArguments)) {
            $urlArguments = explode(',', $urlArguments);
        }
        if (\count($urlArguments) !== $this->pushPackage->getCountOfExpectedArguments()) {
            \header('HTTP/1.0 400 Bad Request Invalid Number Of Arguments');
            exit;
        }
        $buttonText = \trim($_POST['button-text'] ?? $_GET['button-text'] ?? ''); // if empty then MacOS will use default one (View)
        $deviceToken = $this->getDeviceToken($userAuthenticationToken);
        if (!$deviceToken) {
            \header('HTTP/1.0 404 Not Found Device by Given User Authentication Token');
            exit;
        }
        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $text,
                    'action' => $buttonText
                ]
            ],
            'url-args' => $urlArguments
        ];
        $jsonPayload = \json_encode($payload);
        if (!$jsonPayload) {
            throw new Exceptions\CanNotEncodePushPayloadToJson(
                'Can not encode to JSON a payload ' . \var_export($payload, true)
            );
        }
        if (\strlen($jsonPayload) > 256) {
            if ($userAuthenticationToken === '') {
                \header('HTTP/1.0 400 Bad Request Payload To Sent Is Too Long');
                exit;
            }
        }
        $this->sendPushNotification($jsonPayload, \str_replace(' ', '', $deviceToken));
    }

    /**
     * @param string $userAuthenticationToken
     * @return string Empty string of no matching device token has been found
     */
    abstract protected function getDeviceToken(string $userAuthenticationToken): string;

    /**
     * @param string $jsonPayload
     * @param string $deviceToken
     */
    abstract protected function sendPushNotification(string $jsonPayload, string $deviceToken);
}