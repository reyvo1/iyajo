-- TAMASYA: lengkapi peta role statis -> access group.
--
-- requireRoles() sudah menyelesaikan daftar role statis lewat access group
-- (rbac_legacy_role_sets -> rbac_access_groups -> rbac_access_group_roles).
-- Tiga daftar role yang dipakai di route TIDAK punya peta, sehingga 51 call site
-- masih Closure Role statis dan tidak bisa dikelola dari panel Kelola Izin.
--
-- Setiap daftar di bawah dipetakan ke access group yanganggotanya PERSIS SAMA
-- dengan daftar role statis, jadi perilaku route tidak berubah sama sekali;
-- yang berubah hanya route tersebut menjadi dapat dikelola dari UI.
--
-- Idempoten: INSERT IGNORE, aman dijalankan berulang.
--
-- 1) "admin" -> grup Admin yang sudah ada.
-- 2) Sembilan role (termasuk Keuangan) -> grup "all_staff" yang sudah ada.
-- 3) Delapan role (tanpa Keuangan) -> grup baru, karena "all_staff" memuat
--    Keuangan dan memakainya akan menambah akses bagi Keuangan.
INSERT IGNORE INTO `rbac_legacy_role_sets` (`role_set`,`group_key`) VALUES
  ('admin','admin_only'),
  ('admin,cleaning_service,finance,keamanan,koki,lain_lain,manager,receptionist,tukang_kebun','all_staff');

INSERT IGNORE INTO `rbac_access_groups` (`group_key`,`label`,`is_system`) VALUES
  ('all_staff_no_finance','Seluruh staf operasional tanpa Keuangan',1);

INSERT IGNORE INTO `rbac_access_group_roles` (`group_key`,`role_key`) VALUES
  ('all_staff_no_finance','admin'),
  ('all_staff_no_finance','cleaning_service'),
  ('all_staff_no_finance','keamanan'),
  ('all_staff_no_finance','koki'),
  ('all_staff_no_finance','lain_lain'),
  ('all_staff_no_finance','manager'),
  ('all_staff_no_finance','receptionist'),
  ('all_staff_no_finance','tukang_kebun');

INSERT IGNORE INTO `rbac_legacy_role_sets` (`role_set`,`group_key`) VALUES
  ('admin,cleaning_service,keamanan,koki,lain_lain,manager,receptionist,tukang_kebun','all_staff_no_finance');
