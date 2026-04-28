<?php

namespace App\Controller\Pages;

use App\Model\Entity\ContactsSearch;
use App\Model\Entity\CampaignVoice;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportContactsService
{
    private ?array $ObUser;
    private ?int $campaignId;
    private bool $requireCampaign;
    private string $type;
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

    public function import(array $file): int
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
            throw new Exception('Tipo de arquivo não suportado.');
        }

        $inserted = match ($extension) {
            'csv'  => $this->importCsv($file['tmp_name']),
            'xlsx', 'xls' => $this->importExcel($file['tmp_name']),
        };

        if ($this->type === 'voice' && $this->voiceListId) {
            CampaignVoice::updateTotalContacts($this->voiceListId, $inserted);
        }

        return $inserted;
    }

    private function importCsv(string $path): int
    {
        $handle = fopen($path, "r");
        if (!$handle) throw new Exception('Erro ao abrir CSV.');

        $firstRow = fgetcsv($handle, 1000, ";");
        if (!$firstRow) throw new Exception('CSV vazio.');

        $headers = array_map(fn($h) => $this->normalizeText($h), $firstRow);

        if ($this->type === 'voice' && !$this->voiceListId) {
            $this->voiceListId = CampaignVoice::createVoiceList(
                $this->ObUser['id'],
                $this->ObUser['tenancy_id'],
                $this->voiceListName
            );
        }

        $inserted = 0;

        while (($row = fgetcsv($handle, 1000, ";")) !== false) {

            $rowData = $this->processRow($headers, $row);

            if (!empty($rowData['name']) && $this->isValidPhone($rowData['phone'] ?? '')) {
                $this->saveContactFull($rowData);
                $inserted++;
            }
        }

        fclose($handle);
        return $inserted;
    }

    private function importExcel(string $path): int
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (empty($rows)) throw new Exception('Planilha vazia.');

        $headers = array_map(fn($h) => $this->normalizeText((string)$h), $rows[0]);
        unset($rows[0]);

        if ($this->type === 'voice' && !$this->voiceListId) {
            $this->voiceListId = CampaignVoice::createVoiceList(
                $this->ObUser['id'],
                $this->ObUser['tenancy_id'],
                $this->voiceListName
            );
        }

        $inserted = 0;

        foreach ($rows as $row) {

            $rowData = $this->processRow($headers, $row);

            if (!empty($rowData['name']) && $this->isValidPhone($rowData['phone'] ?? '')) {
                $this->saveContactFull($rowData);
                $inserted++;
            }
        }

        return $inserted;
    }

    private function processRow(array $headers, array $row): array
    {
        $data = [];
        $extra = [];

        foreach ($headers as $index => $colName) {

            $value = trim((string)($row[$index] ?? ''));

            if (in_array($colName, ['nome','name','cliente','contato','pessoa'])) {
                $data['name'] = $value;

            } elseif (in_array($colName, ['telefone','phone','celular','numero','whatsapp'])) {
                $data['phone'] = $this->normalizePhone($value);

            } elseif (in_array($colName, ['cpf','documento','doc'])) {
                $cpf = preg_replace('/\D+/', '', $value);

                $data['cpf'] = $cpf;       // coluna fixa
                $extra['cpf'] = $cpf;      // também no JSON
            } else {
                if ($value !== '') {
                    $extra[$colName] = $value;
                }
            }
        }

        $data['extra_data'] = !empty($extra)
            ? json_encode($extra, JSON_UNESCAPED_UNICODE)
            : null;

        return $data;
    }

    private function saveContactFull(array $data): void
    {
        if ($this->type === 'voice') {

            if (!$this->voiceListId) {
                throw new Exception('Lista de voz não criada.');
            }

            CampaignVoice::addContactToList(
                $this->voiceListId,
                $data['name'] ?? null,
                $data['phone'] ?? null,
                $data['cpf'] ?? null,
                $data['extra_data'] ?? null
            );
        }
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return preg_replace('/[^a-z0-9]/', '', $text);
    }

    private function normalizePhone(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input);

        if (strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    private function isValidPhone(string $phone): bool
    {
        return strlen($phone) >= 12 && str_starts_with($phone, '55');
    }
}