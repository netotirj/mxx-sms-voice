<?php

namespace App\Controller\Pages;

use App\Model\Entity\ContactsSearch;
use App\Model\Entity\CampaignVoice;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportContactsServiceBk
{
    private ?array $ObUser;
    private ?int $campaignId;
    private bool $requireCampaign;
    private string $type; // 'sms' ou 'voice'
    private ?string $voiceListName;
    private ?int $voiceListId = null;

    public function __construct(
        $ObUser,
        ?int $campaignId = null,
        bool $requireCampaign = false,
        string $type = 'sms',
        ?string $voiceListName = null
    ) {
        $this->ObUser = $ObUser;
        $this->campaignId = $campaignId;
        $this->requireCampaign = $requireCampaign;
        $this->type = strtolower($type);
        $this->voiceListName = $voiceListName;
    }

    /**
     * Importa contatos a partir de CSV ou Excel
     * @throws Exception
     */
    public function import(array $file): int
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
            throw new Exception('Tipo de arquivo não suportado. Envie um CSV ou Excel.');
        }

        $inserted = match ($extension) {
            'csv'  => $this->importCsv($file['tmp_name']),
            'xlsx', 'xls' => $this->importExcel($file['tmp_name']),
            default => throw new Exception('Formato inválido.')
        };

        if ($this->type === 'voice' && $this->voiceListId) {
            CampaignVoice::updateTotalContacts($this->voiceListId, $inserted);
        }

        return $inserted;
    }

    /**
     * Importa CSV
     * @throws Exception
     */
    private function importCsv(string $path): int
    {
        $handle = fopen($path, "r");
        if (!$handle) throw new Exception('Falha ao abrir o CSV.');

        $firstRow = fgetcsv($handle, 1000, ";", '"', "\\");
        if (!$firstRow) throw new Exception('CSV vazio.');

        $hasHeader = $this->detectHeader($firstRow);
        $headerMap = [];

        if ($hasHeader) {
            $headerMap = array_flip(array_map(fn($h) => strtolower(trim($h)), $firstRow));
        } else {
            $headerMap = ['name' => 0, 'phone' => 1];
            fseek($handle, 0);
        }

        $nameIndex = $this->findColumnIndex($headerMap, 'name');
        $phoneIndex = $this->findColumnIndex($headerMap, 'phone');

        if ($nameIndex === null || $phoneIndex === null) {
            throw new Exception('Não foi possível identificar as colunas Nome e Telefone.');
        }

        // cria lista VOZ se necessário
        if ($this->type === 'voice' && !$this->voiceListId) {
            $this->voiceListId = CampaignVoice::createVoiceList(
                $this->ObUser['id'],
                $this->ObUser['tenancy_id'],
                $this->voiceListName
            );
        }

        $inserted = 0;
        while (($data = fgetcsv($handle, 1000, ";", '"', "\\")) !== false) {
            if (count($data) < max($nameIndex, $phoneIndex) + 1) continue;

            $name = trim($data[$nameIndex]);
            $phone = $this->normalizePhone($data[$phoneIndex]);

            if ($name && $this->isValidPhone($phone)) {
                $this->saveContact($name, $phone);
                $inserted++;
            }
        }

        fclose($handle);
        return $inserted;
    }

    /**
     * Importa Excel (xlsx/xls)
     * @throws Exception
     */
    private function importExcel(string $path): int
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (empty($rows)) throw new Exception('Planilha vazia ou inválida.');

        $firstRow = $rows[0];
        $hasHeader = $this->detectHeader($firstRow);
        $headerMap = [];

        if ($hasHeader) {
            $header = array_map(fn($h) => strtolower(trim((string)$h)), $firstRow);
            unset($rows[0]);
            $headerMap = array_flip($header);
        } else {
            $headerMap = ['name' => 0, 'phone' => 1];
        }

        $nameIndex = $this->findColumnIndex($headerMap, 'name');
        $phoneIndex = $this->findColumnIndex($headerMap, 'phone');

        if ($nameIndex === null || $phoneIndex === null) {
            throw new Exception('Não foi possível identificar as colunas Nome e Telefone.');
        }

        if ($this->type === 'voice' && !$this->voiceListId) {
            $this->voiceListId = CampaignVoice::createVoiceList(
                $this->ObUser['id'],
                $this->ObUser['tenancy_id'],
                $this->voiceListName
            );
        }

        $inserted = 0;
        foreach ($rows as $row) {
            $name = trim((string)$row[$nameIndex]);
            $phone = $this->normalizePhone($row[$phoneIndex]);

            if ($name && $this->isValidPhone($phone)) {
                $this->saveContact($name, $phone);
                $inserted++;
            }
        }

        return $inserted;
    }

    private function detectHeader(array $row): bool
    {
        foreach ($row as $value) {
            if (preg_match('/[a-zA-Z]/', $value)) return true;
        }
        return false;
    }

    private function findColumnIndex(array $map, string $type): ?int
    {
        foreach ($map as $header => $index) {
            $normalized = $this->normalizeText($header);
            if ($type === 'name') {
                $patterns = ['nome', 'name', 'cliente', 'contato', 'user', 'pessoa'];
            } else {
                $patterns = ['telefone', 'phone', 'celular', 'whatsapp', 'numero', 'contactnumber'];
            }
            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) return $index;
            }
        }
        return $map[$type] ?? null;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return preg_replace('/[^a-z0-9]/', '', $text);
    }

    private function normalizePhone(string $input): string
    {
        $input = str_replace(',', '.', $input);
        if (stripos($input, 'e') !== false) $input = number_format((float)$input, 0, '', '');
        $digits = preg_replace('/\D+/', '', $input);
        if (strlen($digits) === 11) $digits = '55'.$digits;
        if (strlen($digits) === 13 && !str_starts_with($digits,'55')) $digits = '55'.substr($digits,-11);
        return $digits;
    }

    private function isValidPhone(string $phone): bool
    {
        return strlen($phone) >= 13 && str_starts_with($phone,'55');
    }

    /**
     * @throws Exception
     */
    private function saveContact(string $name, string $phone): void
    {
        if ($this->type === 'sms') {
            if ($this->requireCampaign && !$this->campaignId) {
                throw new Exception('Campanha obrigatória.');
            }

            $contact = new ContactsSearch();
            $contact->tenancy_id = (string)$this->ObUser['tenancy_id'];
            $contact->user_id = (int)$this->ObUser['id'];
            $contact->name = $name;
            $contact->phone = $phone;
            $contact->campaign_id = $this->campaignId ?? null;
            $contact->created_at = date('Y-m-d H:i:s');
            $contact->updated_at = date('Y-m-d H:i:s');
            $contact->register();

        } elseif ($this->type === 'voice') {
            if (!$this->voiceListId) {
                throw new Exception('Lista de voz não criada.');
            }

            CampaignVoice::addContactToList($this->voiceListId, $name, $phone);
        }
    }
}
























/*class ImportContactsService
{
    private ?array $ObUser;
    private ?int $campaignId;
    private bool $requireCampaign;
    private string $type; // 'sms' ou 'voice'
    private ?string $voiceListName;

    public function __construct(
        $ObUser,
        ?int $campaignId = null,
        bool $requireCampaign = false,
        string $type = 'sms',
        ?string $voiceListName = null
    ) {
        $this->ObUser = $ObUser;
        $this->campaignId = $campaignId;
        $this->requireCampaign = $requireCampaign;
        $this->type = strtolower($type);
        $this->voiceListName = $voiceListName;
    }

    /**
     * Importa contatos a partir de CSV ou Excel
     * @throws Exception
     */
    /*public function import(array $file): int
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
            throw new Exception('Tipo de arquivo não suportado. Envie um CSV ou Excel.');
        }

        $inserted = match ($extension) {
            'csv'  => $this->importCsv($file['tmp_name']),
            'xlsx', 'xls' => $this->importExcel($file['tmp_name']),
            default => throw new Exception('Formato inválido.')
        };

        if ($this->type === 'voice' && $this->voiceListName) {
            $this->updateVoiceListCount();
        }

        return $inserted;
    }

    /**
     * @throws Exception
     */
    /*private function importCsv(string $path): int
    {
        $handle = fopen($path, "r");
        if (!$handle) throw new Exception('Falha ao abrir o CSV.');

        $firstRow = fgetcsv($handle, 1000, ";", '"', "\\");
        if (!$firstRow) throw new Exception('CSV vazio.');

        // Detecta se o arquivo possui cabeçalho
        $hasHeader = $this->detectHeader($firstRow);
        $headerMap = [];

        if ($hasHeader) {
            $headerMap = array_flip(array_map(fn($h) => strtolower(trim($h)), $firstRow));
        } else {
            // Se não tiver cabeçalho, assume ordem padrão: name, phone
            $headerMap = ['name' => 0, 'phone' => 1];
            // Retorna a primeira linha para ser processada
            fseek($handle, 0);
        }

        $nameIndex = $this->findColumnIndex($headerMap, 'name');
        $phoneIndex = $this->findColumnIndex($headerMap, 'phone');

        if ($nameIndex === null || $phoneIndex === null) {
            throw new Exception('Não foi possível identificar as colunas Nome e Telefone.');
        }

        $inserted = 0;
        while (($data = fgetcsv($handle, 1000, ";", '"', "\\")) !== false) {
            if (count($data) < max($nameIndex, $phoneIndex) + 1) continue;

            $name = trim($data[$nameIndex]);
            $phone = $this->normalizePhone($data[$phoneIndex]);

            if ($name && $this->isValidPhone($phone)) {
                $this->saveContact($name, $phone);
                $inserted++;
            }
        }

        fclose($handle);
        return $inserted;
    }

    /**
     * @throws Exception
     */
    /*private function importExcel(string $path): int
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (empty($rows)) throw new Exception('Planilha vazia ou inválida.');

        $firstRow = $rows[0];
        $hasHeader = $this->detectHeader($firstRow);
        $headerMap = [];

        if ($hasHeader) {
            $header = array_map(fn($h) => strtolower(trim((string)$h)), $firstRow);
            unset($rows[0]);
            $headerMap = array_flip($header);
        } else {
            $headerMap = ['name' => 0, 'phone' => 1];
        }

        $nameIndex = $this->findColumnIndex($headerMap, 'name');
        $phoneIndex = $this->findColumnIndex($headerMap, 'phone');

        if ($nameIndex === null || $phoneIndex === null) {
            throw new Exception('Não foi possível identificar as colunas Nome e Telefone.');
        }

        $inserted = 0;
        foreach ($rows as $row) {
            $name = trim((string)$row[$nameIndex]);
            $phone = $this->normalizePhone($row[$phoneIndex]);

            if ($name && $this->isValidPhone($phone)) {
                $this->saveContact($name, $phone);
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Detecta se a primeira linha é cabeçalho ou dados
     */
    /*private function detectHeader(array $row): bool
    {
        // Se algum campo contém letras (provável nome da coluna), é cabeçalho
        foreach ($row as $value) {
            if (preg_match('/[a-zA-Z]/', $value)) return true;
        }
        return false;
    }

    private function findColumnIndex(array $map, string $type): ?int
    {
        foreach ($map as $header => $index) {
            $normalized = $this->normalizeText($header);
            if ($type === 'name') {
                $patterns = ['nome','name','fullname','client','cliente','contato','usuario','user','pessoa','destinatario','alvo'];
            } else {
                $patterns = ['telefone','tel','phone','fone','cel','celular','whatsapp','whats','numero','number','contactnumber','mobile','mob','phonenumber','ntelefone'];
            }

            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) return $index;
            }
        }
        return $map[$type] ?? null; // fallback se não encontrar
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return preg_replace('/[^a-z0-9]/', '', $text);
    }

    private function normalizePhone(string $input): string
    {
        $input = str_replace(',', '.', $input);
        if (stripos($input, 'e') !== false) $input = number_format((float)$input, 0, '', '');
        $digits = preg_replace('/\D+/', '', $input);
        if (strlen($digits) === 11) $digits = '55'.$digits;
        if (strlen($digits) === 13 && !str_starts_with($digits,'55')) $digits = '55'.substr($digits,-11);
        return $digits;
    }

    private function isValidPhone(string $phone): bool
    {
        return strlen($phone) >= 13 && str_starts_with($phone,'55');
    }

    /**
     * @throws Exception
     */
    /*private function saveContact(string $name, string $phone): void
    {
        if ($this->type === 'sms') {
            if ($this->requireCampaign && !$this->campaignId) {
                throw new Exception('Campanha obrigatória.');
            }

            $contact = new ContactsSearch();
            $contact->tenancy_id = (string)$this->ObUser['tenancy_id'];
            $contact->user_id = (int)$this->ObUser['id'];
            $contact->name = $name;
            $contact->phone = $phone;
            $contact->campaign_id = $this->campaignId ?? null;
            $contact->created_at = date('Y-m-d H:i:s');
            $contact->updated_at = date('Y-m-d H:i:s');
            $contact->register();

        } elseif ($this->type === 'voice') {
            if (!$this->voiceListName) throw new Exception('Lista de voz não especificada.');

            $contact = new VoiceListContacts();
            $contact->tenancy_id = (string)$this->ObUser['tenancy_id'];
            $contact->user_id = (int)$this->ObUser['id'];
            $contact->name = $this->voiceListName;
            $contact->phone = $phone;
            $contact->status = 'pending';
            $contact->created_at = date('Y-m-d H:i:s');
            $contact->updated_at = date('Y-m-d H:i:s');
            $contact->register();
        }
    }

    private function updateVoiceListCount(): void
    {
        $list = VoiceList::getById($this->voiceListId);
        if ($list) {
            $list->total_contacts = VoiceListContacts::countByList($this->voiceListId);
            $list->updated_at = date('Y-m-d H:i:s');
            $list->update();
        }
    }
}*/
