CREATE TABLE IF NOT EXISTS checklists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jk_name VARCHAR(255),
  manager_name VARCHAR(255),
  checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  houses JSON,
  score_total FLOAT,
  score_territory FLOAT,
  score_containers FLOAT,
  score_cleaning FLOAT,
  status ENUM('draft','submitted') DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS checklist_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checklist_id INT,
  zone_id VARCHAR(50),
  item_num VARCHAR(10),
  value ENUM('ok','fail','na'),
  floors JSON,
  entrances JSON,
  comment TEXT,
  FOREIGN KEY (checklist_id) REFERENCES checklists(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS checklist_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  checklist_id INT,
  item_num VARCHAR(10),
  filepath VARCHAR(500),
  FOREIGN KEY (checklist_id) REFERENCES checklists(id) ON DELETE CASCADE
);
