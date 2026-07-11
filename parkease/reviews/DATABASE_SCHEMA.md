# Database Schema & Setup Guide

## Database Configuration

### Connection Details
- **Database File**: `parkease/config/database.php`
- **Connection Type**: PDO (PHP Data Objects)
- **Database Engine**: MySQL/MariaDB
- **Default Host**: localhost
- **Default Username**: root
- **Default Password**: (empty)

---

## Required Tables

### 1. Reviews Table

#### Table Definition
```sql
CREATE TABLE reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parking_id INT NOT NULL,
    user_id INT NOT NULL,
    rating INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parking_id) REFERENCES parking_spaces(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_parking (parking_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at),
    INDEX idx_rating (rating)
);
```

#### Column Details

| Column | Type | Size | Constraints | Purpose |
|--------|------|------|-------------|---------|
| id | INT | 11 | PRIMARY KEY, AUTO_INCREMENT | Unique review identifier |
| parking_id | INT | 11 | NOT NULL, FOREIGN KEY | References parking_spaces.id |
| user_id | INT | 11 | NOT NULL, FOREIGN KEY | References users.id |
| rating | INT | 11 | NOT NULL, CHECK (1-5) | Star rating (1-5) |
| comment | TEXT | 65535 | NOT NULL | Review text content |
| created_at | TIMESTAMP | - | DEFAULT CURRENT_TIMESTAMP | Creation datetime |

#### Indexes
- **idx_parking**: Speeds up queries filtering by parking location
- **idx_user**: Speeds up queries filtering by reviewer
- **idx_created**: Speeds up sorting by date
- **idx_rating**: Speeds up filtering by rating

### 2. Users Table (Required Columns)

The users table must already exist in your database with these columns:

```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('parker', 'owner') NOT NULL,
    -- ... other columns as per your schema
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### Required Columns for Reviews
| Column | Type | Purpose |
|--------|------|---------|
| id | INT | User identifier |
| first_name | VARCHAR(100) | Reviewer's first name |
| last_name | VARCHAR(100) | Reviewer's last name |

### 3. Parking Spaces Table (Required Columns)

The parking_spaces table must already exist with these columns:

```sql
CREATE TABLE parking_spaces (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    owner_id INT NOT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    parking_type VARCHAR(50),
    hourly_rate DECIMAL(10, 2),
    daily_rate DECIMAL(10, 2),
    monthly_rate DECIMAL(10, 2),
    available_spots INT,
    total_spots INT,
    description TEXT,
    amenities JSON,
    images JSON,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    INDEX idx_active (is_active),
    INDEX idx_city (city)
);
```

#### Required Columns for Reviews
| Column | Type | Purpose |
|--------|------|---------|
| id | INT | Parking lot identifier |
| name | VARCHAR(255) | Parking lot name |
| is_active | BOOLEAN | Whether lot is active |

---

## Installation Steps

### Step 1: Create Reviews Table

```sql
CREATE TABLE reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parking_id INT NOT NULL,
    user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parking_id) REFERENCES parking_spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_parking (parking_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at),
    INDEX idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Step 2: Verify Users Table

Check that users table exists:
```sql
DESCRIBE users;
```

Must have columns: `id`, `first_name`, `last_name`

### Step 3: Verify Parking Spaces Table

Check that parking_spaces table exists:
```sql
DESCRIBE parking_spaces;
```

Must have columns: `id`, `name`, `is_active`

### Step 4: Test Connections

Test database connection by running reviews.php:
```
http://localhost/Park_Ease/reviews.php
```

Should display without errors.

---

## Queries Used by Reviews Page

### Get All Reviews
```sql
SELECT r.*, u.first_name, u.last_name, p.name as parking_name
FROM reviews r
JOIN users u ON r.user_id = u.id
JOIN parking_spaces p ON r.parking_id = p.id
ORDER BY r.created_at DESC
LIMIT 10 OFFSET 0;
```

### Count Total Reviews
```sql
SELECT COUNT(*) FROM reviews r
JOIN users u ON r.user_id = u.id
JOIN parking_spaces p ON r.parking_id = p.id;
```

### Search Reviews
```sql
SELECT r.*, u.first_name, u.last_name, p.name as parking_name
FROM reviews r
JOIN users u ON r.user_id = u.id
JOIN parking_spaces p ON r.parking_id = p.id
WHERE p.name LIKE ? 
   OR u.first_name LIKE ?
   OR u.last_name LIKE ?
   OR r.comment LIKE ?
ORDER BY r.created_at DESC
LIMIT 10 OFFSET 0;
```

### Filter by Rating
```sql
SELECT r.*, u.first_name, u.last_name, p.name as parking_name
FROM reviews r
JOIN users u ON r.user_id = u.id
JOIN parking_spaces p ON r.parking_id = p.id
WHERE r.rating = ?
ORDER BY r.created_at DESC
LIMIT 10 OFFSET 0;
```

### Get Statistics
```sql
SELECT 
    COUNT(*) as total_reviews,
    ROUND(AVG(rating), 1) as avg_rating,
    COUNT(DISTINCT parking_id) as reviewed_parkings
FROM reviews;
```

### Insert Review
```sql
INSERT INTO reviews (parking_id, user_id, rating, comment, created_at)
VALUES (?, ?, ?, ?, NOW());
```

### Get Active Parking Lots (for dropdown)
```sql
SELECT id, name FROM parking_spaces
WHERE is_active = 1
ORDER BY name ASC;
```

---

## Data Integrity

### Constraints
- Foreign keys ensure referential integrity
- Check constraint ensures rating is 1-5
- NOT NULL ensures all fields are required
- CASCADE delete removes reviews when parking/user deleted

### Data Types
- `INT` for IDs (11 digits)
- `INT CHECK (1-5)` for ratings
- `TEXT` for comments (up to 65,535 characters)
- `TIMESTAMP` for dates

---

## Backup & Recovery

### Backup Reviews Table
```sql
-- Full backup
SELECT * INTO OUTFILE '/backup/reviews_backup.sql'
FROM reviews;

-- Or use mysqldump
mysqldump -u root parkease_db reviews > reviews_backup.sql
```

### Restore Reviews Table
```sql
LOAD DATA INFILE '/backup/reviews_backup.sql'
INTO TABLE reviews;

-- Or restore from dump
mysql -u root parkease_db < reviews_backup.sql
```

---

## Performance Optimization

### Query Optimization
1. **Indexes**: Ensure all indexes are in place
2. **Query Plans**: Use EXPLAIN to analyze queries
3. **Pagination**: Use LIMIT/OFFSET to reduce data transfer
4. **Caching**: Consider caching for statistics

### Index Maintenance
```sql
-- Analyze table
ANALYZE TABLE reviews;

-- Optimize table
OPTIMIZE TABLE reviews;

-- Check index status
SHOW INDEX FROM reviews;
```

---

## Monitoring

### Check Table Status
```sql
SELECT * FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'parkease_db' 
AND TABLE_NAME = 'reviews';
```

### Count Reviews
```sql
SELECT COUNT(*) as total_reviews FROM reviews;
```

### Check Oldest/Newest Reviews
```sql
SELECT created_at FROM reviews 
ORDER BY created_at ASC LIMIT 1;

SELECT created_at FROM reviews 
ORDER BY created_at DESC LIMIT 1;
```

### Average Rating
```sql
SELECT ROUND(AVG(rating), 2) as avg_rating FROM reviews;
```

---

## Troubleshooting

### Issue: "Table 'reviews' doesn't exist"
**Solution**: Run the CREATE TABLE statement from Step 1

### Issue: "Foreign key constraint fails"
**Causes**:
- parking_id doesn't exist in parking_spaces
- user_id doesn't exist in users
- Tables don't exist yet

**Solution**: Verify parking_spaces and users tables exist first

### Issue: "Column 'first_name' doesn't exist"
**Solution**: Verify users table has required columns

### Issue: Connection errors
**Solution**: Check `parkease/config/database.php` settings

---

## Best Practices

### Data Validation
- Always validate data on server side
- Check data types match schema
- Verify foreign key references exist
- Use prepared statements to prevent SQL injection

### Performance
- Add indexes on frequently searched columns
- Use LIMIT for pagination
- Archive old reviews if needed
- Monitor table size

### Security
- Use prepared statements
- Validate all inputs
- Use parameterized queries
- Implement proper access control

### Maintenance
- Regular backups
- Monitor disk space
- Check for errors
- Optimize tables periodically

---

## Database Connection Code

### Configuration File Location
`parkease/config/database.php`

### Basic Connection
```php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if ($db) {
    echo "Connected successfully!";
} else {
    echo "Connection failed!";
}
```

### Test Connection
Visit: `http://localhost/Park_Ease/reviews.php`

If page loads without errors, database is connected.

---

## Maintenance Schedule

| Task | Frequency | Purpose |
|------|-----------|---------|
| ANALYZE TABLE | Weekly | Update index statistics |
| OPTIMIZE TABLE | Monthly | Defragment table |
| Backup | Daily | Data recovery |
| Check Errors | Daily | Problem detection |
| Monitor Growth | Monthly | Capacity planning |

---

## Related Files

- **Configuration**: `parkease/config/database.php`
- **Reviews Page**: `reviews.php`
- **Index**: `parkease/index.php`
- **Dashboard**: `parkease/dashboard.php`

---

**Last Updated**: February 26, 2026
**Version**: 2.0
