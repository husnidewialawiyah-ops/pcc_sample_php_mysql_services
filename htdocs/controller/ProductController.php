<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/Product.php';

final class ProductController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function initialiseDatabase(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                category VARCHAR(100) NULL,
                price DECIMAL(12, 2) NULL,
                discount_percentage DECIMAL(8, 2) NULL,
                rating DECIMAL(4, 2) NULL,
                stock INT NULL,
                tags JSON NULL,
                brand VARCHAR(255) NULL,
                sku VARCHAR(100) NULL,
                weight INT NULL,
                dimensions JSON NULL,
                warranty_information VARCHAR(255) NULL,
                shipping_information VARCHAR(255) NULL,
                availability_status VARCHAR(100) NULL,
                reviews JSON NULL,
                return_policy VARCHAR(255) NULL,
                minimum_order_quantity INT NULL,
                product_meta JSON NULL,
                thumbnail TEXT NULL,
                images JSON NULL,
                source_created_at DATETIME NULL,
                source_updated_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY products_sku_unique (sku),
                KEY products_category_index (category),
                KEY products_title_index (title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function categories(): void
    {
        $categories = $this->pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
        respond(array_map(
            fn (string $category): array => [
                'slug' => $category,
                'name' => ucwords(str_replace('-', ' ', $category)),
                'url' => '/products/category/' . $category,
            ],
            $categories
        ));
    }

    public function categoryList(): void
    {
        respond($this->pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function index(): void
    {
        $limit = min(max((int) ($_GET['limit'] ?? 30), 0), 1000);
        $skip = max((int) ($_GET['skip'] ?? 0), 0);
        $search = trim((string) ($_GET['q'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $allowedSorts = ['id' => 'id', 'title' => 'title', 'price' => 'price', 'rating' => 'rating', 'stock' => 'stock'];
        $sortBy = $allowedSorts[$_GET['sortBy'] ?? 'id'] ?? 'id';
        $order = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $where = [];
        $parameters = [];
        if ($search !== '') {
        $where[] = '(title LIKE :search_title OR description LIKE :search_description OR brand LIKE :search_brand)';

        $keyword = '%' . $search . '%';

        $parameters[':search_title'] = $keyword;
        $parameters[':search_description'] = $keyword;
        $parameters[':search_brand'] = $keyword;
        }
        if ($category !== '') {
            $where[] = 'category = :category';
            $parameters[':category'] = $category;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM products' . $whereSql);
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();
        $sql = 'SELECT * FROM products' . $whereSql . " ORDER BY {$sortBy} {$order}";
        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :skip';
        }
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        if ($limit > 0) {
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->bindValue(':skip', $skip, PDO::PARAM_INT);
        }
        $statement->execute();
        respond([
            'products' => array_map(fn (array $row): array => Product::fromDatabaseRow($row)->toArray(), $statement->fetchAll()),
            'total' => $total,
            'skip' => $skip,
            'limit' => $limit,
        ]);
    }

    public function show(int $id): void
    {
        respond($this->find($id)->toArray());
    }

    public function store(): void
    {
        $product = Product::fromArray($this->requestBody());
        if ($product->toArray()['title'] === '') {
            fail('The title field is required.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO products (title, description, category, price, discount_percentage, rating, stock, tags, brand, sku, weight, dimensions, warranty_information, shipping_information, availability_status, reviews, return_policy, minimum_order_quantity, product_meta, thumbnail, images, source_created_at, source_updated_at)
            VALUES (:title, :description, :category, :price, :discount_percentage, :rating, :stock, :tags, :brand, :sku, :weight, :dimensions, :warranty_information, :shipping_information, :availability_status, :reviews, :return_policy, :minimum_order_quantity, :product_meta, :thumbnail, :images, :source_created_at, :source_updated_at)
        SQL);
        $statement->execute($product->toDatabaseParameters());
        respond($this->find((int) $this->pdo->lastInsertId())->toArray(), 201);
    }

    public function update(int $id): void
    {
        $current = $this->find($id)->toArray();
        $product = Product::fromArray(array_merge($current, $this->requestBody(), ['id' => $id]));
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE products SET title=:title, description=:description, category=:category, price=:price, discount_percentage=:discount_percentage, rating=:rating, stock=:stock, tags=:tags, brand=:brand, sku=:sku, weight=:weight, dimensions=:dimensions, warranty_information=:warranty_information, shipping_information=:shipping_information, availability_status=:availability_status, reviews=:reviews, return_policy=:return_policy, minimum_order_quantity=:minimum_order_quantity, product_meta=:product_meta, thumbnail=:thumbnail, images=:images, source_created_at=:source_created_at, source_updated_at=:source_updated_at WHERE id=:id
        SQL);
        $statement->execute($product->toDatabaseParameters(true));
        respond($this->find($id)->toArray());
    }

    public function destroy(int $id): void
    {
        $product = $this->find($id)->toArray();
        $statement = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute([':id' => $id]);
        $product['isDeleted'] = true;
        $product['deletedOn'] = gmdate('c');
        respond($product);
    }

    private function find(int $id): Product
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $statement->execute([':id' => $id]);
        $product = $statement->fetch();
        if (!$product) {
            fail('Product not found.', 404);
        }
        return Product::fromDatabaseRow($product);
    }

    private function requestBody(): array
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            fail('Request body must be valid JSON.');
        }
        return $body;
    }
}