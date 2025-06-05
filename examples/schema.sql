-- Basic schema for the examples.
-- Users should adapt this to their specific database.

-- For MySQL:
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example data:
-- INSERT INTO users (username, email, status) VALUES ('johndoe', 'john.doe@example.com', 'active');
-- INSERT INTO users (username, email, status) VALUES ('janedoe', 'jane.doe@example.com', 'inactive');
-- INSERT INTO posts (user_id, title, content) VALUES (1, 'My First Post', 'Hello world! This is the content of my first post.');
