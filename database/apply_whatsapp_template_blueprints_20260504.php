<?php

require __DIR__ . '/../bootstrap/cli.php';

use App\Service\WhatsAppTemplateBlueprintLibrary;

WhatsAppTemplateBlueprintLibrary::seedDefaults();

echo "whatsapp_template_blueprints_ok\n";
