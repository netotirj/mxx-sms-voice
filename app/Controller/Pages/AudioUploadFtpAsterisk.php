<?php

namespace App\Controller\Pages;

class AudioUploadFtpAsterisk
{
    private array $file;
    private string $ftpServer;
    private string $ftpUser;
    private string $ftpPass;
    private string $remoteDir;
    private ?string $remotePath = null;

    public function __construct(
        array  $file,
        string $ftpServer,
        string $ftpUser,
        string $ftpPass,
        string $remoteDir = '/var/lib/asterisk/sounds/voice/'
    )
    {
        $this->file = $file;
        $this->ftpServer = $ftpServer;
        $this->ftpUser = $ftpUser;
        $this->ftpPass = $ftpPass;
        $this->remoteDir = rtrim($remoteDir, '/') . '/';
    }

    /**
     * Envia o arquivo via FTP e cria diretórios se necessário
     */
    public function upload(): bool
    {
        if (!$this->file || $this->file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        $localFile = $this->file['tmp_name'];
        $remoteFile = $this->remoteDir . $this->file['name'];

        $ftp = ftp_connect($this->ftpServer, 21, 10);
        if (!$ftp) return false;

        $login = ftp_login($ftp, $this->ftpUser, $this->ftpPass);
        if (!$login) {
            ftp_close($ftp);
            return false;
        }

        ftp_pasv($ftp, true);

        // 🔹 Garante que a pasta remota exista
        $this->createDirectoryIfNotExists($ftp, $this->remoteDir);

        // 🔹 Faz o upload binário do áudio
        $success = ftp_put($ftp, $remoteFile, $localFile, FTP_BINARY);

        ftp_close($ftp);

        if ($success) {
            $this->remotePath = $remoteFile;
        }

        return $success;
    }

    /**
     * Cria diretórios recursivamente no FTP, se não existirem
     */
    private function createDirectoryIfNotExists($ftp, string $remoteDir): void
    {
        $parts = explode('/', trim($remoteDir, '/'));
        $path = '';

        foreach ($parts as $part) {
            $path .= '/' . $part;
            if (@ftp_chdir($ftp, $path)) {
                continue;
            }
            @ftp_mkdir($ftp, $path);
        }
    }

    /**
     * ❌ EXCLUSÃO DO ARQUIVO NO FTP
     */
    public function delete(string $remoteFilePath): bool
    {
        // ==========================================
        // 🔄 Normalização de caminho
        // ==========================================
        $remoteFilePath = trim($remoteFilePath);
        $remoteFilePath = ltrim($remoteFilePath, '/');

        if ($remoteFilePath === '') {
            return false;
        }

        // Caminho no FTP (sempre começa com "/")
        $ftpPath = '/' . $remoteFilePath;

        // Caminho físico real no servidor Asterisk
        $localBasePath = "/var/lib/asterisk/sounds/voice";
        $localPath = rtrim($localBasePath, '/') . '/' . $remoteFilePath;

        // ==========================================
        // 🔗 Conexão FTP
        // ==========================================
        $ftp = ftp_connect($this->ftpServer, 21, 10);
        if (!$ftp) {
            error_log("FTP ERROR: Falha ao conectar ao servidor FTP.");
            return false;
        }

        if (!ftp_login($ftp, $this->ftpUser, $this->ftpPass)) {
            ftp_close($ftp);
            error_log("FTP ERROR: Falha ao autenticar FTP.");
            return false;
        }

        ftp_pasv($ftp, true);

        // ==========================================
        // 🗑️ 1) Excluir arquivo via FTP
        // ==========================================
        $deletedFTP = @ftp_delete($ftp, $ftpPath);

        ftp_close($ftp);

        if (!$deletedFTP) {
            error_log("FTP ERROR: Não conseguiu excluir arquivo via FTP: $ftpPath");
        }

        // ==========================================
        // 🗑️ 2) Excluir arquivo FÍSICO no Asterisk
        // ==========================================
        $deletedLocal = true;

        if (file_exists($localPath)) {
            $deletedLocal = @unlink($localPath);

            if (!$deletedLocal) {
                error_log("FILE ERROR: Falha ao excluir arquivo físico: $localPath");
            }
        } else {
            error_log("FILE WARNING: Arquivo físico não existe: $localPath");
        }

        // ==========================================
        // 📌 Resultado final
        // ==========================================
        return $deletedFTP || $deletedLocal;
    }
}
