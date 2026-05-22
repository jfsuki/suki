<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Auto-registro de nuevos tenants con OTP por email.
 * El tenant_id siempre se genera internamente — NUNCA se acepta del payload.
 */
final class TenantSelfRegistrationService
{
    public function __construct(
        private \PDO         $db,
        private OtpService   $otpService,
        private EmailService $emailService
    ) {}

    /**
     * Paso 1: Solicitar registro → genera OTP y lo envía al email.
     * Retorna solo confirmación, nunca el código.
     *
     * @param array{email: string, phone?: string, nit: string,
     *              business_name: string, password: string, app_id?: string} $data
     * @return array{ok: bool, message: string, tenant_id?: string}
     */
    public function requestRegistration(array $data): array
    {
        if (empty($data['email']) || empty($data['nit']) || empty($data['business_name']) || empty($data['password'])) {
            return ['ok' => false, 'message' => 'Campos requeridos: email, nit, business_name, password'];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Email no valido'];
        }

        if (strlen($data['password']) < 8) {
            return ['ok' => false, 'message' => 'La contrasena debe tener al menos 8 caracteres'];
        }

        // Generar tenant_id único — nunca del payload
        $tenantId = 'tenant_' . bin2hex(random_bytes(8));

        // Guardar datos temporales mientras el OTP no sea verificado
        $this->storePendingTenant($tenantId, $data);

        $code = $this->otpService->generate($data['email'], 'register');

        $this->emailService->sendNotification($tenantId, [
            'type'    => 'otp_registration',
            'to'      => $data['email'],
            'subject' => 'Codigo de verificacion SUKI — ' . $code,
            'body'    => "Tu codigo de verificacion es: <strong>{$code}</strong>\n\nVigente por 15 minutos.\nSi no solicitaste este registro, ignora este email.",
        ]);

        return [
            'ok'        => true,
            'message'   => 'Codigo enviado al email. Verificar en 15 minutos.',
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * Paso 2: Verificar OTP → activa el tenant y crea el usuario admin.
     */
    public function verifyAndActivate(string $tenantId, string $email, string $code): array
    {
        if (!$this->otpService->verify($email, $code, 'register')) {
            return ['ok' => false, 'message' => 'Codigo invalido o expirado'];
        }

        $pending = $this->getPendingTenant($tenantId);
        if (!$pending) {
            return ['ok' => false, 'message' => 'Datos de registro no encontrados. Solicitar nuevo codigo.'];
        }

        // Crear usuario en auth_users (ProjectRegistry / SQLite) con contraseña real (no el OTP)
        // Firma: createAuthUser(projectId, userId, password, role, tenantId, label)
        $registry = new ProjectRegistry();
        $registry->createAuthUser(
            $tenantId,                    // projectId = tenantId en autoregistro
            $pending['email'],            // userId = email
            $pending['password_hash'],    // plain password — createAuthUser aplica bcrypt internamente
            'admin',
            $tenantId,
            $pending['business_name']
        );

        // Limpiar datos temporales
        $this->deletePendingTenant($tenantId);

        return ['ok' => true, 'message' => 'Cuenta activada. Ya puedes iniciar sesion.', 'tenant_id' => $tenantId];
    }

    private function storePendingTenant(string $tenantId, array $data): void
    {
        // La columna se llama password_hash pero almacena la contraseña sin hashear.
        // createAuthUser() aplica bcrypt — hacerlo aquí causaría doble hash.
        // La fila es eliminada inmediatamente tras la activación (ventana máx. 30 min).
        $stmt = $this->db->prepare(
            'INSERT INTO otp_pending_registrations
             (tenant_id, email, phone, nit, business_name, password_hash, app_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE email=VALUES(email), updated_at=NOW()'
        );
        $stmt->execute([
            $tenantId,
            $data['email'],
            $data['phone'] ?? '',
            $data['nit'],
            $data['business_name'],
            $data['password'],          // plain — createAuthUser() aplica bcrypt
            $data['app_id'] ?? 'suki_erp',
        ]);
    }

    private function getPendingTenant(string $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM otp_pending_registrations WHERE tenant_id = ? AND created_at > NOW() - INTERVAL 30 MINUTE'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deletePendingTenant(string $tenantId): void
    {
        $stmt = $this->db->prepare('DELETE FROM otp_pending_registrations WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
    }
}
