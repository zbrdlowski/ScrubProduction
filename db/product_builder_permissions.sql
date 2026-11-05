-- Registers an unassigned role only. No employee role assignments are changed.
INSERT INTO auth_permissions (permission_key,module_key,label,description,risk_level,sort_order,is_active)
VALUES ('products.view','Products','Zobraziť produkty a listingy','Samostatný modul tvorby produktov a UNI kategórií.','normal',600,1),
('products.manage','Products','Spravovať produkty a UNI kategórie','Tvorba a úprava produktov, import a správa UNI kategórií.','normal',610,1)
ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description);
INSERT INTO auth_roles (role_key,label,description,is_system,is_active,sort_order)
VALUES ('product_manager','Products manager','Správa samostatnej databázy produktov a UNI kategórií.',1,1,500)
ON DUPLICATE KEY UPDATE label=VALUES(label);
INSERT IGNORE INTO auth_role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM auth_roles r CROSS JOIN auth_permissions p
WHERE r.role_key='product_manager' AND p.permission_key IN ('products.view','products.manage');
