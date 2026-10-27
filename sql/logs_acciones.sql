-- Bitácora de acciones en BD, ligada al inicio de sesión (logs_login.id)
CREATE TABLE IF NOT EXISTS logs_acciones (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    login_id    INT NULL COMMENT 'logs_login.id de la sesión que originó la acción',
    session_id  VARCHAR(128) NULL,
    usuario_id  INT NULL,
    correo      VARCHAR(150) NULL,
    origen      VARCHAR(20) NULL COMMENT 'agencia | facturacion',
    accion      VARCHAR(30) NOT NULL COMMENT 'INSERT | UPDATE | DELETE | LOGOUT',
    tabla       VARCHAR(64) NULL,
    registro_id VARCHAR(64) NULL,
    detalle     TEXT NULL COMMENT 'JSON con los datos relevantes de la acción',
    ip          VARCHAR(45) NULL,
    url         VARCHAR(255) NULL,
    fecha       DATETIME NOT NULL,
    KEY idx_login (login_id),
    KEY idx_fecha (fecha),
    KEY idx_usuario (usuario_id, origen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
