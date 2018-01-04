<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\Apple;

use Granam\Strict\Object\StrictObject;

/**
 * This is mostly just a syntax sugar of original https://github.com/connorlacombe/Safari-Push-Notifications/blob/master/createPushPackage.php
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
    /** @var string */
    private $pathToWebsiteJson;
    /** @var array|string[] */
    private $pathToIcons = [];
    /** @var string */
    private $temporaryDir;

    public function __construct(
        string $pathToWebsiteJson,
        string $pathToIcon16x16Png,
        string $pathToIcon16x16DoublePng,
        string $pathToIcon32x32Png,
        string $pathToIcon32x32DoublePng,
        string $pathToIcon128x128Png,
        string $pathToIcon128x128DoublePng,
        string $temporaryDir = null
    )
    {
        $this->pathToWebsiteJson = $pathToWebsiteJson;
        $this->pathToIcons['icon_16x16.png'] = $pathToIcon16x16Png;
        $this->pathToIcons['icon_16x16@2x.png'] = $pathToIcon16x16DoublePng;
        $this->pathToIcons['icon_32x32.png'] = $pathToIcon32x32Png;
        $this->pathToIcons['icon_32x32@2x.png'] = $pathToIcon32x32DoublePng;
        $this->pathToIcons['icon_128x128.png'] = $pathToIcon128x128Png;
        $this->pathToIcons['icon_128x128@2x.png'] = $pathToIcon128x128DoublePng;
        $this->temporaryDir = $temporaryDir ?? \sys_get_temp_dir();
    }

    /**
     * Creates the push package, ZIP it and returns the path to that archive.
     *
     * @param string $certificatePath
     * @param string $certificatePassword
     * @param string $intermediateCertificatePath
     * @return string
     * @throws \Granam\Apple\Exceptions\CanNotCreateTemporaryPackageDir
     * @throws \Granam\Apple\Exceptions\CanNotCopyWebsiteJsonToPackage
     * @throws \Granam\Apple\Exceptions\CanNotCreateZipArchive
     * @throws \Granam\Apple\Exceptions\CanNotAddFileToZipArchive
     * @throws \Granam\Apple\Exceptions\CanNotCloseZipArchive
     * @throws \Granam\Apple\Exceptions\CanNotCreateDirForIconSet
     * @throws \Granam\Apple\Exceptions\CanNotCopyIcon
     * @throws \Granam\Apple\Exceptions\CanNotCalculateSha1FromFile
     * @throws \Granam\Apple\Exceptions\CanNotEncodeManifestDataToJson
     * @throws \Granam\Apple\Exceptions\CanNotSaveManifestJsonFile
     * @throws \Granam\Apple\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Apple\Exceptions\CanNotReadCertificateData
     * @throws \Granam\Apple\Exceptions\CanNotGetResourceFromOpenedCertificate
     * @throws \Granam\Apple\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     * @throws \Granam\Apple\Exceptions\CanNotSignManifest
     * @throws \Granam\Apple\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Apple\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Apple\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Apple\Exceptions\CanNotSaveDerSignatureToFile
     */
    public function createPushPackage(
        string $certificatePath,
        string $certificatePassword,
        string $intermediateCertificatePath
    ): string
    {
        // Create a temporary directory in which to assemble the push package
        $packageDir = $this->createTemporaryPackageDir();
        $this->copyRawPushPackageFiles($packageDir);
        $this->createManifestFile($packageDir);
        $this->createSignature($packageDir, $certificatePath, $certificatePassword, $intermediateCertificatePath);

        return $this->packageRawData($packageDir);
    }

    /**
     * @return string
     * @throws \Granam\Apple\Exceptions\CanNotCreateTemporaryPackageDir
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
     * @param string $packageDir
     * @throws \Granam\Apple\Exceptions\CanNotCreateDirForIconSet
     * @throws \Granam\Apple\Exceptions\CanNotCopyIcon
     * @throws \Granam\Apple\Exceptions\CanNotCopyWebsiteJsonToPackage
     */
    private function copyRawPushPackageFiles(string $packageDir)
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
        if (!\copy($this->pathToWebsiteJson, $websiteJsonDestinationPath)) {
            throw new Exceptions\CanNotCopyWebsiteJsonToPackage(
                "Can not copy $this->pathToWebsiteJson to $websiteJsonDestinationPath"
            );
        }
    }

    /**
     * Creates the manifest file by calculating the SHA1 hashes for all of the raw files in the package.
     *
     * @param string $packageDir
     * @throws \Granam\Apple\Exceptions\CanNotCalculateSha1FromFile
     * @throws \Granam\Apple\Exceptions\CanNotEncodeManifestDataToJson
     * @throws \Granam\Apple\Exceptions\CanNotSaveManifestJsonFile
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
     * @param string $certificatePath
     * @param string $certificatePassword
     * @param string $intermediateCertificatePath
     * @throws \Granam\Apple\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Apple\Exceptions\CanNotReadCertificateData
     * @throws \Granam\Apple\Exceptions\CanNotGetResourceFromOpenedCertificate
     * @throws \Granam\Apple\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     * @throws \Granam\Apple\Exceptions\CanNotSignManifest
     * @throws \Granam\Apple\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Apple\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Apple\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Apple\Exceptions\CanNotSaveDerSignatureToFile
     */
    private function createSignature(
        string $packageDir,
        string $certificatePath,
        string $certificatePassword,
        string $intermediateCertificatePath
    )
    {
        $certificateData = $this->getCertificateData($certificatePath, $certificatePassword);
        $signatureFullPath = "$packageDir/signature";
        $certificateResource = $this->getCertificateResource($certificateData, $certificatePath);
        $privateKeyResource = $this->getPrivateKeyResource($certificateData, $certificatePassword, $certificatePath);
        $manifestFullPath = "$packageDir/manifest.json";
        $this->signManifest(
            $manifestFullPath,
            $signatureFullPath,
            $certificateResource,
            $privateKeyResource,
            $intermediateCertificatePath
        );
        $this->convertSignatureFromPemToDer($signatureFullPath);
    }

    /**
     * @param string $certificatePath
     * @param string $certificatePassword
     * @return array
     * @throws \Granam\Apple\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Apple\Exceptions\CanNotReadCertificateData
     */
    private function getCertificateData(string $certificatePath, string $certificatePassword): array
    {
        // Load the push notification certificate
        $pkcs12 = \file_get_contents($certificatePath);
        if (!$pkcs12) {
            throw new Exceptions\CanNotGetCertificateContent("Can not get certificate content from $certificatePath");
        }
        $certificateData = [];
        if (!\openssl_pkcs12_read($pkcs12, $certificateData, $certificatePassword)) {
            throw new Exceptions\CanNotReadCertificateData(
                "Can not read certificate data, fetched from $certificatePath, using given password"
            );
        }

        return $certificateData;
    }

    /**
     * @param array $certificateData
     * @param string $certificatePath
     * @return resource
     * @throws \Granam\Apple\Exceptions\CanNotGetResourceFromOpenedCertificate
     */
    private function getCertificateResource(array $certificateData, string $certificatePath)
    {
        $certificateResource = \openssl_x509_read($certificateData['cert']);
        if (!$certificateResource) {
            throw new Exceptions\CanNotGetResourceFromOpenedCertificate(
                "Can not open certificate after successful decoding of $certificatePath"
            );
        }

        return $certificateResource;
    }

    /**
     * @param array $certificateData
     * @param string $certificatePassword
     * @param string $certificatePath
     * @return bool|resource
     * @throws \Granam\Apple\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     */
    private function getPrivateKeyResource(array $certificateData, string $certificatePassword, string $certificatePath)
    {
        $privateKey = \openssl_pkey_get_private($certificateData['pkey'], $certificatePassword);
        if (!$privateKey) {
            throw new Exceptions\CanNotGetPrivateKeyFromOpenedCertificate(
                "Can not get private key after successful decoding of $certificatePath"
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
     * @param string $intermediateCertificatePath
     * @throws \Granam\Apple\Exceptions\CanNotSignManifest
     */
    private function signManifest(
        string $manifestFullPath,
        string $outputSignatureFullPath,
        $certificateResource,
        $privateKey,
        string $intermediateCertificatePath
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
            $intermediateCertificatePath
        );
        if (!$signed) {
            throw new Exceptions\CanNotSignManifest(
                "Failed signing of the manifest file $manifestFullPath using given certificates"
            );
        }
    }

    /**
     * @param string $pemSignatureFullPath
     * @throws \Granam\Apple\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Apple\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Apple\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Apple\Exceptions\CanNotSaveDerSignatureToFile
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
     * @throws \Granam\Apple\Exceptions\CanNotCreateZipArchive
     * @throws \Granam\Apple\Exceptions\CanNotAddFileToZipArchive
     * @throws \Granam\Apple\Exceptions\CanNotCloseZipArchive
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