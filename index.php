<?php
require __DIR__ . '/bootstrap/app.php';

use \App\Http\Router;

$obRouter = new Router(URL);


//Inclui a Rota do Dashboard
include __DIR__ . '/routes/pages.php';

$obRouter->run()
    ->sendResponse();