<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use finfo;

final class RegistrationService
{
    private PDO $db;
    private PdfExtractorService $pdfExtractor;
    private string $storageBase;

    public function __construct(PDO $db, PdfExtractorService $pdfExtractor, ?string $storageBase = null)
    {
        $this->db = $db;
        $this->pdfExtractor = $pdfExtractor;
        // Se asume que el storage vive en project/storage/
        $this->storageBase = $storageBase ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * Procesa el registro de un nuevo usuario/empresa.
     * 
     * @param array{project_id?: string, tenant_id?: string, password: string, phone_number?: string, area_code?: string, business_desc?: string, alt_phone?: string, alt_email?: string} $input
     * @param array{tmp_name: string, size: int, error: int} $uploadedFile
     * @return array{success: bool, user_id?: string|int, error?: string}
     */
    public function register(array $input, array $uploadedFile): array
    {
        try {
            // 1. Validar MIME real del PDF
            if (!is_file($uploadedFile['tmp_name'])) {
                return ['success' => false, 'error' => 'Archivo no encontrado.'];
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($uploadedFile['tmp_name']);
            if ($mimeType !== 'application/pdf') {
                return ['success' => false, 'error' => 'El archivo debe ser un PDF válido. No se aceptan imágenes.'];
            }

            // 2. Validar tamaño (5 MB)
            if ($uploadedFile['size'] > 5 * 1024 * 1024) {
                return ['success' => false, 'error' => 'El archivo no debe superar los 5 MB.'];
            }

            // 3. Mover fuera del webroot
            $tenantId = $input['tenant_id'] ?? 't_' . bin2hex(random_bytes(4));
            $storageDir = $this->storageBase . DIRECTORY_SEPARATOR . 'rut' . DIRECTORY_SEPARATOR . $tenantId;
            if (!is_dir($storageDir)) {
                if (!mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                    return ['success' => false, 'error' => 'Error al crear directorio de almacenamiento.'];
                }
            }
            
            $fileName = 'rut_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $destPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;
            
            if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
                return ['success' => false, 'error' => 'Error al mover el archivo al almacenamiento seguro.'];
            }

            // 4. Extraer datos del RUT
            $ext = $this->pdfExtractor->extractFromRut($destPath);

            // 5. Hash de contraseña (BCRYPT)
            $hash = password_hash($input['password'], PASSWORD_BCRYPT);

            // 6. Insertar en auth_users
            $stmt = $this->db->prepare("
                INSERT INTO auth_users
                    (id, project_id, nit, full_name, area_code, phone_number, alt_phone, alt_email, 
                     country, department, city, primary_activity, secondary_activity, 
                     tax_responsibilities, business_desc, rut_path, password_hash, tenant_id, is_active)
                VALUES
                    (:id, :project_id, :nit, :full_name, :area_code, :phone_number, :alt_phone, :alt_email,
                     :country, :department, :city, :primary_activity, :secondary_activity, 
                     :tax_responsibilities, :business_desc, :rut_path, :password_hash, :tenant_id, :is_active)
            ");
            
            $userId = 'u_' . bin2hex(random_bytes(4));
            $projectId = $input['project_id'] ?? 'default'; 
            
            $stmt->execute([
                ':id'                   => $userId,
                ':project_id'           => $projectId,
                ':nit'                  => $ext['nit'] ?? ($input['nit'] ?? ''),
                ':full_name'            => $ext['full_name'] ?? ($input['full_name'] ?? ''),
                ':area_code'            => $input['area_code'] ?? '+57',
                ':phone_number'         => preg_replace('/[^0-9]/', '', $input['phone_number'] ?? ($ext['phone'] ?? '')),
                ':alt_phone'            => preg_replace('/[^0-9]/', '', $input['alt_phone'] ?? ''),
                ':alt_email'            => strtolower($input['alt_email'] ?? ''),
                ':country'              => $ext['country'] ?? 'COLOMBIA',
                ':department'           => $ext['location']['department'] ?? '',
                ':city'                 => $ext['location']['city'] ?? '',
                ':primary_activity'     => $ext['activities']['primary'] ?? '',
                ':secondary_activity'   => $ext['activities']['secondary'] ?? '',
                ':tax_responsibilities' => json_encode($ext['responsibilities_desc'] ?? []),
                ':business_desc'        => $input['business_desc'] ?? '',
                ':rut_path'             => $destPath,
                ':password_hash'        => $hash,
                ':tenant_id'            => $tenantId,
                ':is_active'            => 0, // Inactivo hasta aprobación
            ]);

            return [
                'success' => true, 
                'user_id' => $userId, 
                'tenant_id' => $tenantId,
                'is_pj' => $ext['is_pj'] ?? false,
                'detected_info' => $ext,
                'msg' => 'Registro exitoso. Un asesor verificará tus datos pronto.'
            ];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error interno en el registro: ' . $e->getMessage()];
        }
    }
}
