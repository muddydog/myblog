<?php

namespace Util;

/**
 * @package Util
 * @summary 文件下载相关的通用方法.
 */
class Download
{
    /**
     * Method  dlFileResumable
     *
     * @static
     *
     * @param string $file
     * @param string $downloadName
     * @param bool   $isResume
     */
    public static function dlFileResumable($file, $downloadName = null, $isResume = true)
    {
        // First, see if the file exists
        if (!is_file($file)) {
            static::notFoundError('');
        }

        // Gather relevent info about file
        $size     = filesize($file);
        $fileInfo = pathinfo($file);

        if (empty($downloadName)) {
            // workaround for IE filename bug with multiple periods / multiple dots in filename
            // that adds square brackets to filename - eg. setup.abc.exe becomes setup[1].abc.exe
            $downloadName = (strstr($_SERVER['HTTP_USER_AGENT'], 'MSIE')) ? preg_replace('/\./', '%2e', $fileInfo['basename'], substr_count($fileInfo['basename'], '.') - 1) : $fileInfo['basename'];
        }

        $fileExtension = strtolower($fileInfo['extension']);

        //This will set the Content-Type to the appropriate setting for the file
        switch ($fileExtension) {
            case 'exe':
            case 'rswk2':
                $ctype = 'application/octet-stream';
                break;
            case 'zip':
                $ctype = 'application/zip';
                break;
            case 'mp3':
                $ctype = 'audio/mpeg';
                break;
            case 'mpg':
                $ctype = 'video/mpeg';
                break;
            case 'avi':
                $ctype = 'video/x-msvideo';
                break;
            default:
                $ctype = 'application/force-download';
                break;
        }

        //check if http_range is sent by browser (or download manager)
        if ($isResume && isset($_SERVER['HTTP_RANGE'])) {
            list($sizeUnit, $rangeOrig) = explode('=', $_SERVER['HTTP_RANGE'], 2);

            if (trim($sizeUnit) == 'bytes') {
                //multiple ranges could be specified at the same time, but for simplicity only serve the first range
                //http://tools.ietf.org/id/draft-ietf-http-range-retrieval-00.txt
                list($range, $extra_ranges) = explode(',', $rangeOrig, 2);
            } else {
                $range = '';
            }
        } else {
            $range = '';
        }

        //figure out download piece from range (if set)
        list($seekStart, $seekEnd) = explode('-', $range, 2);

        //set start and end based on range (if set), else set defaults
        //also check for invalid ranges.
        $seekEnd   = (empty($seekEnd)) ? ($size - 1) : min(abs(intval($seekEnd)), ($size - 1));
        $seekStart = (empty($seekStart) || $seekEnd < abs(intval($seekStart))) ? 0 : max(abs(intval($seekStart)), 0);

        //add headers if resumable
        if ($isResume) {
            //Only send partial content header if downloading a piece of the file (IE workaround)
            if ($seekStart > 0 || $seekEnd < ($size - 1)) {
                header('HTTP/1.1 206 Partial Content');
            }

            header('Accept-Ranges: bytes');
            header('Content-Range: bytes ' . $seekStart . '-' . $seekEnd . '/' . $size);
        }

        //headers for IE Bugs (is this necessary?)
        //header("Cache-Control: cache, must-revalidate");
        //header("Pragma: public");

        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . ($seekEnd - $seekStart + 1));

        //open the file
        $fp = fopen($file, 'rb');
        //seek to start of missing part
        fseek($fp, $seekStart);

        //start buffered download
        while (!feof($fp)) {
            //reset time limit for big files
            set_time_limit(0);
            print(fread($fp, 1024 * 8));
            flush();
            ob_flush();
        }

        fclose($fp);
        exit;
    }

    /**
     * Method  notFoundError
     *
     * @static
     *
     * @param string $error
     */
    public static function notFoundError($error = '')
    {
        header('HTTP/1.1 404 Not Found');

        die($error ? "<b>$error</b>" : '');
    }

    /**
     * Add files and sub-directories in a folder to zip file.
     *
     * @param string      $folder
     * @param \ZipArchive $zipFile
     * @param int         $exclusiveLength Number of text to be exclusived from the file path.
     */
    private static function folderToZip($folder, &$zipFile, $exclusiveLength)
    {
        $handle = opendir($folder);
        while (false !== $f = readdir($handle)) {
            if ($f != '.' && $f != '..') {
                $filePath = "$folder/$f";
                // Remove prefix from file path before add to zip.
                $localPath = substr($filePath, $exclusiveLength);
                if (is_file($filePath)) {
                    $zipFile->addFile($filePath, $localPath);
                } elseif (is_dir($filePath)) {
                    // Add sub-directory.
                    $zipFile->addEmptyDir($localPath);
                    self::folderToZip($filePath, $zipFile, $exclusiveLength);
                }
            }
        }
        closedir($handle);
    }

    /**
     * Zip a folder (include itself).
     * Usage:
     *   HZip::zipDir('/path/to/sourceDir', '/path/to/out.zip');
     *
     * @param string $sourcePath Path of directory to be zip.
     * @param string $outZipPath Path of output zip file.
     */
    public static function zipDir($sourcePath, $outZipPath)
    {
        $pathInfo   = pathInfo($sourcePath);
        $parentPath = $pathInfo['dirname'];
        $dirName    = $pathInfo['basename'];

        $z = new \ZipArchive();
        $z->open($outZipPath, \ZIPARCHIVE::CREATE);
        $z->addEmptyDir($dirName);
        self::folderToZip($sourcePath, $z, strlen("$parentPath/"));
        $z->close();
    }
}
