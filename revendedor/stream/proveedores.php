<?php
// Wrapper del panel REVENDEDOR: fija el contexto y reusa la MISMA página del gestor del admin.
// El aislamiento por owner_id (= id del revendedor) lo aplica admin/_auth.php vía stream_owner_id().
define('STREAM_CTX', 'revendedor');
require __DIR__ . '/../../admin/stream/proveedores.php';
