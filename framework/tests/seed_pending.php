<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Core\ProjectRegistry;
use App\Core\RegistrationService;
use App\Core\PdfExtractorService;

$registry = new ProjectRegistry();
$reg = new RegistrationService($registry->db(), new PdfExtractorService());

// Simular un registro pendiente de SUKI DEVOPS SAS
$data = [
    ':id' => 'u_' . bin2hex(random_bytes(4)),
    ':project_id' => 'p_default',
    ':nit' => '9017129520',
    ':full_name' => 'SUKI DEVOPS SAS',
    ':area_code' => '+57',
    ':phone_number' => '3008430122',
    ':alt_phone' => '3105551234',
    ':alt_email' => 'admin@suki.com.co',
    ':password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
    ':country' => 'COLOMBIA',
    ':department' => 'BOGOTA',
    ':city' => 'BOGOTA',
    ':primary_activity' => '6201',
    ':secondary_activity' => '6202',
    ':tax_responsibilities' => json_encode(['48' => 'IVA', '37' => 'Facturación Electrónica']),
    ':tenant_id' => 'tenant_demo'
];

$db = $registry->db();
$sql = "INSERT INTO auth_users (
            id, project_id, nit, full_name, area_code, phone_number, 
            alt_phone, alt_email, country, department, city, 
            primary_activity, secondary_activity, tax_responsibilities, 
            password_hash, is_active, tenant_id
        ) VALUES (
            :id, :project_id, :nit, :full_name, :area_code, :phone_number, 
            :alt_phone, :alt_email, :country, :department, :city, 
            :primary_activity, :secondary_activity, :tax_responsibilities, 
            :password_hash, 0, :tenant_id
        )";

$stmt = $db->prepare($sql);
$stmt->execute($data);

echo "Usuario de prueba sembrado correctamente.\n";
