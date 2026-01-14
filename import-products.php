<?php
/**
 * Product Import Script for ZEEKR Dealer System
 * Run via WP-CLI: wp eval-file wp-content/plugins/dealer-system/import-products.php
 */

if (!defined('ABSPATH')) {
    // Allow running from WP-CLI
    if (php_sapi_name() !== 'cli') {
        die('This script must be run from WP-CLI');
    }
}

// Load WordPress if needed
if (!function_exists('wc_get_product')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

class ZEEKR_Product_Importer {
    private $csv_file;
    private $imported = 0;
    private $updated = 0;
    private $failed = 0;
    private $categories_cache = [];

    public function __construct($csv_file) {
        $this->csv_file = $csv_file;
    }

    public function import() {
        if (!file_exists($this->csv_file)) {
            echo "Error: CSV file not found: {$this->csv_file}\n";
            return false;
        }

        $handle = fopen($this->csv_file, 'r');
        if (!$handle) {
            echo "Error: Cannot open CSV file\n";
            return false;
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            echo "Error: Cannot read CSV header\n";
            return false;
        }

        $total = 0;
        $batch = [];
        $batch_size = 50;

        echo "Starting import...\n";
        $start_time = microtime(true);

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            $batch[] = $data;
            $total++;

            if (count($batch) >= $batch_size) {
                $this->process_batch($batch);
                $batch = [];
                echo "Processed: {$total} products...\r";
            }
        }

        // Process remaining
        if (!empty($batch)) {
            $this->process_batch($batch);
        }

        fclose($handle);

        $elapsed = round(microtime(true) - $start_time, 2);
        echo "\n\n=== Import Complete ===\n";
        echo "Total processed: {$total}\n";
        echo "New products: {$this->imported}\n";
        echo "Updated products: {$this->updated}\n";
        echo "Failed: {$this->failed}\n";
        echo "Time: {$elapsed}s\n";

        return true;
    }

    private function process_batch($batch) {
        foreach ($batch as $data) {
            $this->import_product($data);
        }
        // Clear caches after each batch
        wp_cache_flush();
    }

    private function import_product($data) {
        try {
            $sku = trim($data['sku']);

            if (empty($sku)) {
                $this->failed++;
                return;
            }

            // Check if product exists by SKU
            $existing_id = wc_get_product_id_by_sku($sku);

            if ($existing_id) {
                // Update existing product
                $product = wc_get_product($existing_id);
                if (!$product) {
                    $this->failed++;
                    return;
                }
            } else {
                // Create new product
                $product = new WC_Product_Simple();
            }

            // Set product data
            $product->set_name($data['name']);
            $product->set_sku($sku);
            $product->set_status('publish');

            // Prices
            if (!empty($data['regular_price']) && $data['regular_price'] > 0) {
                $product->set_regular_price($data['regular_price']);
            }
            if (!empty($data['sale_price']) && $data['sale_price'] > 0) {
                $product->set_sale_price($data['sale_price']);
            }

            // Stock
            $product->set_manage_stock(true);
            $stock = intval($data['stock_quantity']);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');

            // Category
            if (!empty($data['category']) && $data['category'] !== 'Uncategorized') {
                $category_id = $this->get_or_create_category($data['category']);
                if ($category_id) {
                    $product->set_category_ids([$category_id]);
                }
            }

            // Save product
            $product_id = $product->save();

            if ($product_id) {
                if ($existing_id) {
                    $this->updated++;
                } else {
                    $this->imported++;
                }
            } else {
                $this->failed++;
            }

        } catch (Exception $e) {
            $this->failed++;
            error_log("Product import error for SKU {$data['sku']}: " . $e->getMessage());
        }
    }

    private function get_or_create_category($name) {
        if (isset($this->categories_cache[$name])) {
            return $this->categories_cache[$name];
        }

        // Check if category exists
        $term = get_term_by('name', $name, 'product_cat');

        if ($term) {
            $this->categories_cache[$name] = $term->term_id;
            return $term->term_id;
        }

        // Create category
        $result = wp_insert_term($name, 'product_cat');

        if (!is_wp_error($result)) {
            $this->categories_cache[$name] = $result['term_id'];
            return $result['term_id'];
        }

        return false;
    }
}

// Run import if called directly
if (php_sapi_name() === 'cli' || defined('WP_CLI')) {
    $csv_file = dirname(__FILE__) . '/../../import-products.csv';

    // Check for custom path argument
    if (isset($args) && !empty($args[0])) {
        $csv_file = $args[0];
    }

    echo "CSV File: {$csv_file}\n";

    $importer = new ZEEKR_Product_Importer($csv_file);
    $importer->import();
}
