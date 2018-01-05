<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\Safari;

use Granam\Strict\Object\StrictObject;

/**
 * This is mostly just a syntax sugar of original https://github.com/connorlacombe/Safari-Push-Notifications/
 *
 * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/Introduction/Introduction.html#//apple_ref/doc/uid/TP40013225-CH1-SW1
 */
class PushPackage extends StrictObject
{
    /**
     * Relative names of files required by Apple in a push package.
     *
     * @var array
     */
    private static $manifestRequiredFileNames = [
        'icon.iconset/icon_16x16.png',
        'icon.iconset/icon_16x16@2x.png',
        'icon.iconset/icon_32x32.png',
        'icon.iconset/icon_32x32@2x.png',
        'icon.iconset/icon_128x128.png',
        'icon.iconset/icon_128x128@2x.png',
        'website.json'
    ];

    /**
     * @var array|string[]
     */
    private $pathToIcons = [];
    /**
     * @var string
     */
    private $temporaryDir;
    /**
     * @var string
     */
    private $websiteName;
    /**
     * @var string
     */
    private $websitePushId;
    /**
     * @var array
     */
    private $allowedDomains;
    /**
     * @var string
     */
    private $urlFormatString;
    /**
     * @var string
     */
    private $webServiceUrl;
    /**
     * @var string
     */
    private $certificatePath;
    /**
     * @var string
     */
    private $certificatePassword;
    /**
     * @var string
     */
    private $intermediateCertificatePath;

    /**
     * @param string $certificatePath
     * @param string $certificatePassword
     * @param string $intermediateCertificatePath
     * @param string $websiteName like Safari Push Demo, this is the heading used in Notification Center
     * @param string $websitePushId like web.com.example (web. prefix is required), as specified in your Apple developer account
     * @param array $allowedDomains like [https://api.example.com, https://www.example.com]
     * @param string $urlFormatString like https://www.exmple.com/article.php?id=%@ ('%@' is a two-letter-named placeholder),
     * the URL to go to when the notification is clicked (protocol is required and only http and https are allowed)
     * @param string $webServiceUrl The location used to make requests to your web service. Must start with https.
     * @param string $pathToIcon16x16Png
     * @param string $pathToIcon16x16DoublePng
     * @param string $pathToIcon32x32Png
     * @param string $pathToIcon32x32DoublePng
     * @param string $pathToIcon128x128Png
     * @param string $pathToIcon128x128DoublePng
     * @param string|null $temporaryDir
     * @throws \Granam\Safari\Exceptions\InvalidFormatOfWebsitePushId
     * @throws \Granam\Safari\Exceptions\NoAllowedDomains
     * @throws \Granam\Safari\Exceptions\AllowedDomainHasInvalidFormat
     * @throws \Granam\Safari\Exceptions\InvalidFormatOfLandingUrl
     */
    public function __construct(
        string $certificatePath,
        string $certificatePassword,
        string $intermediateCertificatePath,
        string $websiteName,
        string $websitePushId,
        array $allowedDomains,
        string $urlFormatString,
        string $webServiceUrl,
        string $pathToIcon16x16Png,
        string $pathToIcon16x16DoublePng,
        string $pathToIcon32x32Png,
        string $pathToIcon32x32DoublePng,
        string $pathToIcon128x128Png,
        string $pathToIcon128x128DoublePng,
        string $temporaryDir = null
    )
    {
        $this->certificatePath = $certificatePath;
        $this->certificatePassword = $certificatePassword;
        $this->intermediateCertificatePath = $intermediateCertificatePath;
        $this->websiteName = $websiteName;
        if (!\preg_match('~^web([.]\w+){1,9}~u', $websitePushId)) {
            throw new Exceptions\InvalidFormatOfWebsitePushId(
                "Website push ID should be in format web.com.exanple.foo, got $websitePushId"
            );
        }
        $this->websitePushId = $websitePushId;
        if (!$allowedDomains) {
            throw new Exceptions\NoAllowedDomains(
                'At least single domain allowed to request permissions from an user is required'
            );
        }
        foreach ($allowedDomains as $allowedDomain) {
            if (!\filter_var($allowedDomain, FILTER_VALIDATE_URL)) {
                throw new Exceptions\AllowedDomainHasInvalidFormat(
                    "Given domain allowed to request permissions from an user is invalid: {$allowedDomain}"
                );
            }
        }
        $this->allowedDomains = $allowedDomains;
        if (!\preg_match('~^https?://~', $urlFormatString)) {
            throw new Exceptions\InvalidFormatOfLandingUrl(
                "URL used as landing one after user click has to use http or https protocol, got $urlFormatString"
            );
        }
        $this->urlFormatString = $urlFormatString;
        $this->webServiceUrl = \rtrim($webServiceUrl, '/');
        $this->pathToIcons['icon_16x16.png'] = $pathToIcon16x16Png;
        $this->pathToIcons['icon_16x16@2x.png'] = $pathToIcon16x16DoublePng;
        $this->pathToIcons['icon_32x32.png'] = $pathToIcon32x32Png;
        $this->pathToIcons['icon_32x32@2x.png'] = $pathToIcon32x32DoublePng;
        $this->pathToIcons['icon_128x128.png'] = $pathToIcon128x128Png;
        $this->pathToIcons['icon_128x128@2x.png'] = $pathToIcon128x128DoublePng;
        $this->temporaryDir = $temporaryDir ?? \sys_get_temp_dir();
    }

    public function getWebsitePushId(): string
    {
        return $this->websitePushId;
    }

    public function getCountOfExpectedArguments(): int
    {
        return \substr_count($this->urlFormatString, '%@');
    }

    /**
     * Creates the push package, ZIP it and returns the path to that archive.
     *
     * @param string $userAuthenticationToken A string that helps you identify the user.
     * It is included in later requests to your web service. This string must be 16 characters or greater.
     * @return string full path to ZIPed package file
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
    public function createPushPackage(string $userAuthenticationToken): string
    {
        // Create a temporary directory in which to assemble the push package
        $packageDir = $this->createTemporaryPackageDir();
        $websiteJsonContent = $this->getWebsiteJsonContent($userAuthenticationToken);
        $this->copyRawPushPackageFiles($packageDir, $websiteJsonContent);
        $this->createManifestFile($packageDir);
        $this->createSignature($packageDir);

        return $this->packageRawData($packageDir);
    }

    /**
     * @return string
     * @throws \Granam\Safari\Exceptions\CanNotCreateTemporaryPackageDir
     */
    private function createTemporaryPackageDir(): string
    {
        $packageDir = $this->temporaryDir . '/' . uniqid('pushPackage', true);
        if (!@\mkdir($packageDir) && !\is_dir($packageDir)) {
            throw new Exceptions\CanNotCreateTemporaryPackageDir("Failed to create temporary dir $packageDir");
        }

        return $packageDir;
    }

    /**
     * @param string $userAuthenticationToken
     * @return string
     * @throws \Granam\Safari\Exceptions\CanNotEncodeWebsiteToJson
     */
    private function getWebsiteJsonContent(string $userAuthenticationToken): string
    {
        if (\mb_strlen($userAuthenticationToken) < 16) {
            // we have to add "authentication_token_" because it has to be at least 16 for some Apple reason
            $userAuthenticationToken = 'authentication_token_' . $userAuthenticationToken;
        }
        $website = [
            'websiteName' => $this->websiteName,
            'websitePushID' => $this->websitePushId,
            'allowedDomains' => $this->allowedDomains,
            'urlFormatString' => $this->urlFormatString,
            'authenticationToken' => $userAuthenticationToken,
            'webServiceURL' => $this->webServiceUrl,
        ];

        $websiteJson = \json_encode($website, JSON_FORCE_OBJECT);
        if (!$websiteJson) {
            throw new Exceptions\CanNotEncodeWebsiteToJson(
                'Can not turn to JSON object website data ' . \var_export($website, true)
            );
        }

        return $websiteJson;
    }

    /**
     * @param string $packageDir
     * @param string $websiteJsonContent
     * @throws \Granam\Safari\Exceptions\CanNotCreateDirForIconSet
     * @throws \Granam\Safari\Exceptions\CanNotCopyIcon
     * @throws \Granam\Safari\Exceptions\CanNotSaveWebsiteJsonToPackage
     */
    private function copyRawPushPackageFiles(string $packageDir, string $websiteJsonContent)
    {
        $iconSetDir = $packageDir . '/icon.iconset'; // this dir name is required by Apple
        if (!@\mkdir($iconSetDir) && !\is_dir($packageDir)) {
            throw new Exceptions\CanNotCreateDirForIconSet('Can not create dir ' . $iconSetDir);
        }
        foreach ($this->pathToIcons as $iconName => $pathToIcon) {
            $iconDestinationPath = $iconSetDir . '/' . $iconName;
            if (!\copy($pathToIcon, $iconDestinationPath)) {
                throw new Exceptions\CanNotCopyIcon("Can not copy icon $pathToIcon to $iconDestinationPath");
            }
        }
        $websiteJsonDestinationPath = $packageDir . '/website.json';
        if (!\file_put_contents($websiteJsonDestinationPath, $websiteJsonContent)) {
            throw new Exceptions\CanNotSaveWebsiteJsonToPackage(
                "Can not save $websiteJsonContent to $websiteJsonDestinationPath"
            );
        }
    }

    /**
     * Creates the manifest file by calculating the SHA1 hashes for all of the raw files in the package.
     *
     * @param string $packageDir
     * @throws \Granam\Safari\Exceptions\CanNotCalculateSha1FromFile
     * @throws \Granam\Safari\Exceptions\CanNotEncodeManifestDataToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveManifestJsonFile
     */
    private function createManifestFile(string $packageDir)
    {
        // Obtain SHA1 hashes of all the files in the push package
        $manifestData = [];
        foreach (self::$manifestRequiredFileNames as $fileName) {
            $fileFullPath = "$packageDir/$fileName";
            $manifestData[$fileName] = \sha1_file($fileFullPath);
            if (!$manifestData[$fileName]) {
                throw new Exceptions\CanNotCalculateSha1FromFile("Can not calculate SHA1 from $fileFullPath");
            }
        }
        $jsonEncoded = \json_encode($manifestData, JSON_FORCE_OBJECT);
        if (!$jsonEncoded) {
            throw new Exceptions\CanNotEncodeManifestDataToJson(
                'Can not encode to JSON manifest data ' . \var_export($manifestData, true)
            );
        }
        $manifestFullPath = "$packageDir/manifest.json";
        if (!\file_put_contents($manifestFullPath, $jsonEncoded)) {
            throw new Exceptions\CanNotSaveManifestJsonFile(
                "Can not fill $manifestFullPath by JSON encoded data $jsonEncoded"
            );
        }
    }

    /**
     * Creates a signature of the manifest using the push notification certificate.
     *
     * @param string $packageDir
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
    private function createSignature(string $packageDir)
    {
        $certificateData = $this->getCertificateData();
        $signatureFullPath = "$packageDir/signature";
        $certificateResource = $this->getCertificateResource($certificateData);
        $privateKeyResource = $this->getPrivateKeyResource($certificateData);
        $manifestFullPath = "$packageDir/manifest.json";
        $this->signManifest(
            $manifestFullPath,
            $signatureFullPath,
            $certificateResource,
            $privateKeyResource
        );
        $this->convertSignatureFromPemToDer($signatureFullPath);
    }

    /**
     * @return array
     * @throws \Granam\Safari\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Safari\Exceptions\CanNotReadCertificateData
     */
    private function getCertificateData(): array
    {
        // Load the push notification certificate
        $pkcs12 = \file_get_contents($this->certificatePath);
        if (!$pkcs12) {
            throw new Exceptions\CanNotGetCertificateContent("Can not get certificate content from $this->certificatePath");
        }
        $certificateData = [];
        if (!\openssl_pkcs12_read($pkcs12, $certificateData, $this->certificatePassword)) {
            throw new Exceptions\CanNotReadCertificateData(
                "Can not read certificate data, fetched from $this->certificatePath, using given password"
            );
        }

        return $certificateData;
    }

    /**
     * @param array $certificateData
     * @return resource
     * @throws \Granam\Safari\Exceptions\CanNotGetResourceFromOpenedCertificate
     */
    private function getCertificateResource(array $certificateData)
    {
        $certificateResource = \openssl_x509_read($certificateData['cert']);
        if (!$certificateResource) {
            throw new Exceptions\CanNotGetResourceFromOpenedCertificate(
                "Can not open certificate after successful decoding of $this->certificatePath"
            );
        }

        return $certificateResource;
    }

    /**
     * @param array $certificateData
     * @return bool|resource
     * @throws \Granam\Safari\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     */
    private function getPrivateKeyResource(array $certificateData)
    {
        $privateKey = \openssl_pkey_get_private($certificateData['pkey'], $this->certificatePassword);
        if (!$privateKey) {
            throw new Exceptions\CanNotGetPrivateKeyFromOpenedCertificate(
                "Can not get private key after successful decoding of $this->certificatePath"
            );
        }

        return $privateKey;
    }

    /**
     * Sign the manifest.json file with the private key from the certificate.
     *
     * @param string $manifestFullPath
     * @param string $outputSignatureFullPath
     * @param $certificateResource
     * @param $privateKey
     * @throws \Granam\Safari\Exceptions\CanNotSignManifest
     */
    private function signManifest(
        string $manifestFullPath,
        string $outputSignatureFullPath,
        $certificateResource,
        $privateKey
    )
    {
        //
        $signed = \openssl_pkcs7_sign(
            $manifestFullPath,
            $outputSignatureFullPath,
            $certificateResource,
            $privateKey,
            [], // no special headers needed
            PKCS7_BINARY | PKCS7_DETACHED,
            $this->intermediateCertificatePath
        );
        if (!$signed) {
            throw new Exceptions\CanNotSignManifest(
                "Failed signing of the manifest file $manifestFullPath using given certificates"
            );
        }
    }

    /**
     * @param string $pemSignatureFullPath
     * @throws \Granam\Safari\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Safari\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Safari\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Safari\Exceptions\CanNotSaveDerSignatureToFile
     */
    private function convertSignatureFromPemToDer(string $pemSignatureFullPath)
    {
        $pemSignatureContent = \file_get_contents($pemSignatureFullPath);
        if (!$pemSignatureContent) {
            throw new Exceptions\CanNotReadPemSignatureFromFile(
                "Can not read content of PEM signature file $pemSignatureFullPath"
            );
        }
        if (!\preg_match('~Content-Disposition:[^\n]+\s*?([A-Za-z0-9+=/\r\n]+)\s*?-----~', $pemSignatureContent, $matches)) {
            throw new Exceptions\UnexpectedContentOfPemSignature('Content of PEM signature is different than expected');
        }
        $derSignatureContent = \base64_decode($matches[1]);
        if (!$derSignatureContent) {
            throw new Exceptions\CanNotCreateDerSignatureByDecodingToBase64(
                'Decoding of PEM signature content to base 64 failed'
            );
        }
        if (!\file_put_contents($pemSignatureFullPath, $derSignatureContent)) {
            throw new Exceptions\CanNotSaveDerSignatureToFile(
                "Can not save PEM signature content into $pemSignatureFullPath"
            );
        }
    }

    /**
     * Zips the directory structure into a push package, and returns the path to the archive.
     *
     * @param string $packageDir
     * @return string full path to ZIP archive with archived package
     * @throws \Granam\Safari\Exceptions\CanNotCreateZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotAddFileToZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCloseZipArchive
     */
    private function packageRawData(string $packageDir): string
    {
        $zipFileName = "$packageDir.zip";
        $zip = new \ZipArchive();
        if (!$zip->open($zipFileName, \ZipArchive::CREATE)) {
            throw new Exceptions\CanNotCreateZipArchive('Could not create ' . $zipFileName);
        }

        $fileNames = self::$manifestRequiredFileNames;
        $fileNames[] = 'manifest.json';
        $fileNames[] = 'signature';
        foreach ($fileNames as $fileName) {
            if (!$zip->addFile("$packageDir/$fileName", $fileName)) {
                throw new Exceptions\CanNotAddFileToZipArchive("Can not add '$packageDir/$fileName' to ZIP archive");
            }
        }
        if (!$zip->close()) {
            throw new Exceptions\CanNotCloseZipArchive("Can not close ZIP archive $zipFileName");
        }

        return $zipFileName;
    }
}