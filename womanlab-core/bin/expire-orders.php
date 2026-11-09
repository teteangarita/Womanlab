<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
$db = WomanLab\Support\Database::connection();
$query = $db->prepare("UPDATE ordenes SET estado = 'ANULADA' WHERE estado = 'PENDIENTE' AND expira_en <= NOW()");
$query->execute();
fwrite(STDOUT, sprintf("Pedidos vencidos anulados: %d\n", $query->rowCount()));