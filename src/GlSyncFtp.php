<?php

/**
 * Sync local files with ftp server
 *
 * PHP version 5.4
 *
 * @category  GLICER
 * @package   GlSyncFtp
 * @author    Emmanuel ROECKER
 * @author    Rym BOUCHAGOUR
 * @copyright 2015 GLICER
 * @license   MIT
 * @link      http://dev.glicer.com/
 *
 * Created : 22/05/15
 * File : GlSyncFtp.php
 *
 */

namespace GlSyncFtp;

use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Finder\Finder;
use phpseclib\Net\SFTP;

/**
 * Class SFTPConnection
 * @package GLICER\GetterBundle\Ftp
 */
class GlSyncFtp
{
    const DELETE_FILE = 0;
    const DELETE_DIR  = 1;
    const CREATE_DIR  = 2;
    const NEW_FILE    = 3;
    const UPDATE_FILE = 4;

    /**
     * @var SFTP
     */
    private $sftp;

    /**
     * @var string
     */
    private $server;

    /**
     * @var string
     */
    private $user;

    /**
     * @var string
     */
    private $password;

    /**
     * @param string $ftpserver
     * @param string $user
     * @param string $password
     */
    function __construct($ftpserver, $user, $password)
    {
        $this->server   = $ftpserver;
        $this->user     = $user;
        $this->password = $password;
    }

    function __destruct()
    {
        if (isset($this->sftp)) {
            $this->sftp->disconnect();
        }
    }

    /**
     * @param string $root
     * @param array  $listfiles
     * @param array  $listdirs
     *
     * @throws GlSyncFtpException
     */
    public function getAllFiles($root, &$listfiles, &$listdirs)
    {
        $this->login();
        $this->getFiles($root, "", $listfiles, $listdirs);
    }

    /**
     * @throws GlSyncFtpException
     */
    private function login()
    {
        if (isset($this->sftp)) {
            return;
        }

        $this->sftp = new SFTP($this->server);
        if (!$this->sftp->login($this->user, $this->password)) {
            throw new GlSyncFtpException('Login Failed');
        }
    }

    /**
     * @param string $root
     * @param string $relative
     * @param array  $listfiles
     * @param array  $listdirs
     */
    private function getFiles($root, $relative, &$listfiles, &$listdirs)
    {
        $files = $this->sftp->rawlist($root . '/' . $relative);
        if ($files === false) {
            return;
        }
        foreach ($files as $name => $raw) {
            if (($name != '.') && ($name != '..')) {
                if ($raw['type'] == NET_SFTP_TYPE_DIRECTORY) {
                    $listdirs[$relative . '/' . $name] = $raw;
                    $this->getFiles($root, $relative . '/' . $name, $listfiles, $listdirs);
                } else {
                    $listfiles[$relative . '/' . $name] = $raw;
                }
            }
        }
    }


    /**
     * sync local directory with ftp directory
     *
     * @param string   $src
     * @param string   $dst
     * @param callable $syncop
     *
     * @throws GlSyncFtpException
     */
    public function syncDirectory($src, $dst, callable $syncop)
    {
        $this->login();

        $nbrDeleteFile = 0;
        $nbrDeleteDir  = 0;
        $nbrCreateDir  = 0;
        $nbrNewFile    = 0;
        $nbrUpdateFile = 0;

        $files = [];
        $dirs  = [];
        $this->getFiles($dst, "", $files, $dirs);

        // delete on ftp server, files not present in local directory
        foreach ($files as $name => $raw) {
            if (!file_exists($src . $name)) {
                $filepathFtp = $dst . strtr($name, ["\\" => "/"]);
                $syncop(self::DELETE_FILE, $nbrDeleteFile, $filepathFtp);
                $this->sftp->delete($filepathFtp);
                $nbrDeleteFile++;
            }
        }

        // delete on ftp server, unknowns directories
        $dirs = array_reverse($dirs);
        foreach ($dirs as $name => $raw) {
            if (!file_exists($src . $name)) {
                $filepathFtp = $dst . strtr($name, ["\\" => "/"]);
                $syncop(self::DELETE_DIR, $nbrDeleteDir, $filepathFtp);
                $this->sftp->rmdir($filepathFtp);
                $nbrDeleteDir++;
            }
        }

        // create new directories
        $finderdir = new Finder();
        $finderdir->directories()->ignoreDotFiles(false)->followLinks()->in($src)->notName('.git*');

        /**
         * @var SplFileInfo $dir
         */
        foreach ($finderdir as $dir) {
            $dirpathFtp = $dst . "/" . strtr($dir->getRelativePathname(), ["\\" => "/"]);
            $stat       = $this->sftp->stat($dirpathFtp);
            if (!$stat) {
                $syncop(self::CREATE_DIR, $nbrCreateDir, $dirpathFtp);
                $this->sftp->mkdir($dirpathFtp, $dir->getRealPath(), SFTP::SOURCE_LOCAL_FILE);
                $this->sftp->chmod(0755, $dirpathFtp, $dir->getRealPath());
                $nbrCreateDir++;
            }
        }

        // copy new files or update if younger
        $finderdir = new Finder();
        $finderdir->files()->ignoreDotFiles(false)->followLinks()->in($src)->notName('.git*');

        /**
         * @var SplFileInfo $file
         */
        foreach ($finderdir as $file) {
            $filepathFtp = $dst . "/" . strtr($file->getRelativePathname(), ["\\" => "/"]);
            $stat        = $this->sftp->stat($filepathFtp);
            if (!$stat) {
                $syncop(self::NEW_FILE, $nbrNewFile, $filepathFtp);
                $this->sftp->put($filepathFtp, $file->getRealPath(), SFTP::SOURCE_LOCAL_FILE);
                $nbrNewFile++;
            } else {
                $size = $this->sftp->size($filepathFtp);
                if (($file->getMTime() > $stat['mtime']) || ($file->getSize() != $size)) {
                    $syncop(self::UPDATE_FILE, $nbrUpdateFile, $filepathFtp);
                    $this->sftp->put($filepathFtp, $file->getRealPath(), SFTP::SOURCE_LOCAL_FILE);
                    $nbrUpdateFile++;
                }
            }
        }
    }
}

