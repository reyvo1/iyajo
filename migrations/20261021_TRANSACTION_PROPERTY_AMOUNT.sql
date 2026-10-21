-- TAMASYA: nilai transaksi dalam mata uang property.
--
-- Transaksi boleh dicatat dalam mata uang lain (misalnya USD pada property
-- yang menagih IDR). Nominal aslinya tetap disimpan pada `amount` bersama
-- `currency` dan `exchangeRate`, dan hasil konversinya disimpan pada kolom
-- baru `propertyAmount` supaya laporan dapat menjumlahkan seluruh transaksi
-- dalam satu angka mata uang property.
--
-- Kolom `baseAmount` tidak dipakai untuk ini: pada skema kanonik `baseAmount`
-- yang NULL berarti "pajak belum diketahui", bukan "belum dikonversi".
--
-- Idempoten dan tidak mengubah data lama: baris lama dibiarkan NULL, dan
-- pembacanya memperlakukan NULL sebagai "belum ada nilai konversi".

SET @schema := DATABASE();

SET @tx_property_amount := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'propertyAmount'
);
SET @sql := IF(@tx_property_amount = 0,
  'ALTER TABLE `transactions` ADD COLUMN `propertyAmount` decimal(15,2) DEFAULT NULL AFTER `exchangeRate`',
  'SELECT ''transactions.propertyAmount already installed'' AS migration_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
