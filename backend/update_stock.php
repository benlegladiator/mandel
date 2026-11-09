<?php
require_once __DIR__ . '/config/db.php';

echo "Starting update...\n";

// 2. Read new stock list
$stockData = <<<DATA
1	Couché 2 FACE128G 64X92 250 FEUILLES	CAMAIR	 27	IMPRIMERIE COUCHE	RAME	 20,000		 22,000	
2	Couché 2 FACE 250G 64X90 125 FEUILLE	CAMAIR	 9	IMPRIMERIE COUCHE	RAME	 20,500		 22,500	
3	Couché 2 FACE 350G GOLDEN 64X90 100FEUILLE	CAMAIR	 1	IMPRIMERIE COUCHE	RAME			 26,500	
4	Couché 2 FACE 350G 64X90 125 FEUILLE	CAMAIR	 5	IMPRIMERIE COUCHE	RAME	 31,500		 33,000	
5	Couché 2 FACE 170G A4 100 FEUILLE	CAMAIR	 66	COUCHE A4	RAMETTE			 2,000	
6	Couché 2 FACE 200G A4 100FEUILLE	CAMAIR	 19	COUCHE A4	RAMETTE			 2,500	
7	Couché 2 FACE 250G A4 100 FEUILLE	CAMAIR	 17	COUCHE A4	RAMETTE			 3,000	
8	Couché 2 FACE 300G A4 100FEUILLE	CAMAIR	 24	COUCHE A4	RAMETTE			 3,500	
9	Couché 2 FACE 350G A4 100 FEUILLE	CAMAIR	 17	COUCHE A4	RAMETTE			 4,000	
10	Couché 2 FACE 300G A3 100 FEUILLE	CAMAIR	 17	COUCHE A3	RAMETTE			 7,000	
11	Couché 2 FACE 170G A3 100 FEUILLE	CAMAIR	 29	COUCHE A3	RAMETTE			 45,000	
12	Couché 2 FACE 200G A3 100 FEUILLE	CAMAIR	 18	COUCHE A3	RAMETTE			 5,500	
13	Couché 2 FACE 250G A3 100 FEUILLE	CAMAIR	 14	COUCHE A3	RAMETTE			 6,000	
14	Couché 2 face  350G A3 100 FEUILLE	CAMAIR	 20	COUCHE A3	RAMETTE			 7,500	
15	Couché 2 FACE 128G A3 100 FEUILLE	CAMAIR	 1	COUCHE A3	RAMETTE			 4,000	
16	Couché 2 FACE 150 G A3 100 FEUILLE	CAMAIR	 2	COUCHE A4	RAMETTE			 4,500	
17	Couché 1 FACE 350G 64X92 100 FEUILLE	CAMAIR	 12	IMPRIMERIE COUCHE	RAME			 29,000	
18	Couché 1 FACE 350G A4 100 FEUILLE	CAMAIR	 29	COUCHE A4	RAMETTE			 8,000	
19	Couché 1 FACE 350G A3 100 FEUILLE	CAMAIR	 18	COUCHE A3	RAMETTE			 4,000	
20	SATINE BLANC A2	CAMAIR	 2	SATIN A2	RAMETTE			 7,000	
21	PAPIER FORMAT WINNER A4 500 FEUILLE	CAMAIR	 97	PAPIER PHOTOCOPIE A4	RAMETTE	 12,000		 13,000	
22	PAPIER FORMAT MIILK 80G A3 500 FEUILLE	CAMAIR	 34	PAPIER PHOTOCOPIE A4	RAMETTE	 3,300		 4,000	
23	Transparent bleu A4	CAMAIR	 6	FOUNITURE DE BUREAU	PAQUET			 3,000	
24	malmero A4 100 FEUILLE	CAMAIR	 2	FOUNITURE DE BUREAU	PAQUET			 4,000	
25	Chemise de 90	CAMAIR	 306	CHEMISE	PAQUET			 2,600	
26	CHEMISE DE 100	CAMAIR	 258	CHEMISE	PAQUET			 2,800	
27	Chemise de 75	CAMAIR	 102	CHEMISE	PAQUET			 2,225	
28	Sous chemise de 200	CAMAIR	 204	SOUS CHEMISE	PAQUET			 1,750	
29	Chemise de 70	CAMAIR	 507	CHEMISE	PAQUET			 2,000	
30	Chemise importees 	CAMAIR	 30	 CHEMISE	PAQUET			 3,800	
31	detail Bic bleu	CAMAIR	 20	FOUNITURE DE BUREAU	PIECE			 150	
32	craie couleur paquet de 100	CAMAIR	 24	FOUNITURE DE BUREAU	BOITE			 2,500	
33	craie blanche paquet de 100	CAMAIR	 1	FOUNITURE DE BUREAU	BOITE			 1,600	
34	Protege format Lourd	CAMAIR	 7	FOUNITURE DE BUREAU	PAQUET			 5,000	
35	Protege format leger	CAMAIR	 3	FOUNITURE DE BUREAU	PAQUET			 2,000	
36	ENVELOPPE A5 BLANC  50 FEUILLE	CAMAIR	 21	FOUNITURE DE BUREAU	PAQUET			 1,300	
37	ENVELOPPE A6  BLANC LONG MIILK  50 FEUILLE	CAMAIR	 156	FOUNITURE DE BUREAU	PAQUET			 800	
38	ENVELOPPE A5 KAKI 50 FEUILLE	CAMAIR	 64	FOUNITURE DE BUREAU	PAQUET			 1,300	
39	ENVELOPPE A4 BLANC	CAMAIR	 165	FOUNITURE DE BUREAU	PAQUET			 2,000	
40	ENVELOPPE A4 KAKI  50 FEUILLE	CAMAIR	 78	FOUNITURE DE BUREAU	PAQUET			 1,800	
41	ENVELOPPE A3 KAKI FEUILLE	CAMAIR	 106	FOUNITURE DE BUREAU	PAQUET			 3,500	
42	ENVELOPPE A6 BLANC COURT 50 FEUILLE	CAMAIR	 239	FOUNITURE DE BUREAU	PAQUET			 800	
43	Essuie tout premium	CAMAIR	 20	ESSUIT TOUT	PIECE			 2,500	
44	Jumbo basic	CAMAIR	 2	ESSUIT TOUT	PIECE			 1,000	
45	Mouchoire de poche paquet de 10	CAMAIR	 293	ESSUIT TOUT	PAQUET			 500	
46	Registre SIMPLE 200P	CAMAIR	 14	REGISTRE	UNITE			 1,500	
47	Registre MAGISTRAL 300P	CAMAIR	 9	REGISTRE	UNITE			 2,000	
48	Registre MAGISTRAL 400P	CAMAIR	 2	REGISTRE	UNITE			 2,500	
49	Registre MAGISTRAL 500P	CAMAIR	 15	REGISTRE	UNITE			 3,000	
50	Registre 600P	CAMAIR	 15	REGISTRE	UNITE			 3,500	
51	Registre 900P	CAMAIR	 5	REGISTRE	UNITE			 5,500	
52	Registre  1000P	CAMAIR	 5	REGISTRE	UNITE			 6,500	
53	Enveloppe A6 long blanc miilk A6	CAMAIR	50	FOUNITURE DE BUREAU	PAQUET			800	
54	papier format inacopia A4	CAMAIR	46	FOUNITURE DE BUREAU	RAME			3000	
55	SOUS Chemise  de 160	CAMAIR	241	SOUS CHEMISE	UNITE			1500	
56	Encre jaune pour imprimerie	CAMAIR	6	IMPRIMERIE	UNITE			12500	
57	Encre noir pour imprimerie	CAMAIR	2	IMPRIMERIE	UNITE			12500	
58	Encre rose pour imprimerie	CAMAIR	6	IMPRIMERIE	UNITE			12500	
59	Sous chemise de 250	CAMAIR	105	SOUS CHEMISE	PAQUET			2000	
60	Papier format NAVIGATOR A4 500 FEUILLE	CAMAIR	7	PAPIER PHOTOCOPIE A4	RAMETTE			3500	
61	Enveloppe A2	CAMAIR	30	FOUNITURE DE BUREAU	PAQUET			5000	
62	papier format Numero 1 A4 500 FEUILLE	CAMAIR	45	PAPIER PHOTOCOPIE A4	RAMETTE			2800	
63	Sous main A4	CAMAIR	4	FOUNITURE DE BUREAU	PIECE			1500	
64	ENVELOPPE A3 MIILK 100G	CAMAIR	39	FOUNITURE DE BUREAU	PAQUET			3500	
65	REGLE 30cm	CAMAIR	7	FOUNITURE DE BUREAU	UNITE			100	
DATA;

// Get CAMAIR shop_id
$stmt = $pdo->prepare("SELECT id FROM shops WHERE name LIKE '%camair%' LIMIT 1");
$stmt->execute();
$shopId = $stmt->fetchColumn();

if (!$shopId) {
    die("Shop CAMAIR not found.\n");
}
echo "Found CAMAIR shop ID: $shopId\n";

// Delete all existing stock for CAMAIR
$pdo->exec("DELETE FROM product_stock WHERE shop_id = $shopId");
echo "Deleted existing stock for CAMAIR.\n";

$lines = explode("\n", trim($stockData));
$inserted = 0;
$notFound = [];

foreach ($lines as $line) {
    $parts = explode("\t", trim($line));
    if (count($parts) < 4) continue;

    $name = trim($parts[1]);
    $qty = (int)str_replace(',', '', trim($parts[3]));

    // Try to find the product ID by name
    $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $productId = $stmt->fetchColumn();

    if (!$productId) {
        // Try strict match on just a normalized string
        $normName = preg_replace('/\s+/', ' ', strtolower($name));
        $stmt = $pdo->query("SELECT id, name FROM products");
        $found = false;
        while ($row = $stmt->fetch()) {
            if (preg_replace('/\s+/', ' ', strtolower($row['name'])) == $normName) {
                $productId = $row['id'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $notFound[] = $name;
            // Let's create the product
            // Find category
            $catName = trim($parts[4] ?? '');
            $catId = null;
            if ($catName) {
                $cstmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
                $cstmt->execute([$catName]);
                $catId = $cstmt->fetchColumn();
                if (!$catId) {
                    $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$catName]);
                    $catId = $pdo->lastInsertId();
                }
            }
            
            // max id
            $maxId = $pdo->query("SELECT MAX(id) FROM products")->fetchColumn();
            $productId = $maxId ? $maxId + 1 : 1;
            
            $unit = trim($parts[5] ?? 'UNITE');
            $price_purchase = (float)str_replace([' ', ','], '', trim($parts[6] ?? '0'));
            $price_sale = (float)str_replace([' ', ','], '', trim($parts[8] ?? '0'));
            
            $insStmt = $pdo->prepare("INSERT INTO products (id, name, sku, category_id, unit, price_purchase, price_sale) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insStmt->execute([$productId, $name, 'ART-N'.str_pad($productId, 4, '0', STR_PAD_LEFT), $catId, $unit, $price_purchase, $price_sale]);
            echo "Created new product: $name (ID: $productId)\n";
        }
    }

    if ($productId) {
        $stmt = $pdo->prepare("INSERT INTO product_stock (product_id, shop_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$productId, $shopId, $qty]);
        $inserted++;
    }
}

echo "Inserted $inserted products stock for CAMAIR.\n";
if (!empty($notFound)) {
    echo "Products that were not found and created: \n" . implode("\n", $notFound) . "\n";
}
