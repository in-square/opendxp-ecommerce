CREATE DATABASE IF NOT EXISTS coreshop_migration_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON coreshop_migration_test.* TO 'coreshop_test'@'%';
GRANT SELECT ON mysql.columns_priv TO 'coreshop_test'@'%';
