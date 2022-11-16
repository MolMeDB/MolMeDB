<?php 

/**
 * Version 1.15
 * 
 * @author Jakub Juračka <jakub.juracka55@gmail.com>
 */
$upgrade_sql = array
(
    // Parent groups
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Hydrocarbyls', NULL, '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Haloalkanes', NULL, '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Oxygen groups', NULL, '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitrogen groups', NULL, '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfur groups', NULL, '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Phosporus groups', NULL, '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Boron groups', NULL, '11');",

    ###########################################
    ########### HYDROCARBYLS ##################
    ###########################################
    // Functional groups \\\[\\\*:\\\]
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Alkyl', '^(C+\\\[\\\*:\\\])$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Alkenyl', '^(C\\\(=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|C\\\(=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|C=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|C\\\(=C\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|C=C\\\[\\\*:\\\])$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Alkynyl', '^(C\\\(#C\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|C#C\\\[\\\*:\\\])$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Phenyl', '^c1ccc\\\(\\\[\\\*:\\\]\\\)cc1$', '11');",

    // Links
    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Hydrocarbyls' AND et2.type = 11
        WHERE et.name IN ('Alkyl', 'Alkenyl', 'Alkynyl', 'Phenyl'));",

    ###########################################
    ########### Haloalkanes ###################
    ###########################################
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Halo class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Fluoro', '^F\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Chloro', '^Cl\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Bromo', '^Br\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Iodo', '^I\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Haloalkanes' AND et2.type = 11
        WHERE et.name IN ('Halo class'));",

    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Halo class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Halo class' AND et2.name LIKE 'Haloalkanes') as etl ON etl.id = etl.id
        WHERE et.name IN ('Fluoro', 'Chloro', 'Bromo', 'Iodo'));",

    
    ###########################################
    ########### Oxygen groups #################
    ###########################################
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Hydroxyl', '^O\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbonyl', '^O=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Aldehyde', '^O=C\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Haloformyl', '^O=C\\\((Cl|F|I|B)\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Haloformyl[F]', '^O=C\\\(F\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Haloformyl[Cl]', '^O=C\\\(Cl\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Haloformyl[B]', '^O=C\\\(B\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Haloformyl[I]', '^O=C\\\(I\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbonate ester', 'O=C\\\(O\\\[\\\*:\\\]\\\)O\\\[\\\*:\\\]', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carboxylate', 'O=C\\\([O-]\\\)\\\[\\\*:\\\]', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carboxyl', '^O=C\\\(O\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carboalkoxy', '^O=C\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Hydroperoxy', '^OOC\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Peroxy', '^O\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Ether', '^O\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Hemiacetal', '^OC\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Hemiketal', '^OC\\\(O\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Acetal', '^O\\\(C\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Ketal', '^O\\\(C\\\(O\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Orthoester', '^O\\\(C\\\(O\\\[\\\*:\\\]\\\)\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Orthocarbonate ester', '^O\\\(C\\\(O\\\[\\\*:\\\]\\\)\\\(O\\\[\\\*:\\\]\\\)O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carboxylic anhydride', '^O=C\\\(OC\\\(=O\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Oxygen groups' AND et2.type = 11
        WHERE et.name IN ('Hydroxyl', 'Carbonyl', 'Aldehyde', 'Haloformyl', 'Haloformyl[F]', 'Haloformyl[Cl]', 'Haloformyl[B]', 'Haloformyl[I]', 'Carbonate ester', 'Carboxylate', 'Hydroperoxy', 'Peroxy', 'Ether', 'Hemiacetal', 'Hemiketal', 'Acetal', 'Ketal', 'Orthoester', 'Orthocarbonate estser', 'Carboxylic anhydride'));",

    ###########################################
    ########### Nitrogen groups ###############
    ###########################################
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carboxamide', '^O=C\\\(CN\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Amidine', '^N\\\(=C\\\(N\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    // Amines
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Amines class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Primary amine', '^N\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Secondary amine', '^N\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Tertiary amine', '^N\\\(\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, '4° ammonium ion', '^([N+]\\\(\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|N\\\(\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\])$', '11');",
    // Imines
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Imines class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Primary ketimine', '^N=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Secondary ketimine', '^N\\\(=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Primary aldimine', '^N=C\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Secondary aldimine', '^C\\\(=N\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Imide', '^O=C\\\(N\\\(C\\\(=O\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Azide', '^\\\[\\\*:\\\]N=[N+]=[N-]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Azo (Diimide)', '^N\\\(=N\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    // Cyanates
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Cyanates class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Cyanate', '^N#CO\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Isocyanate', '^O=C=N\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitrate', '^O=[N+]\\\([O-]\\\)O\\\[\\\*:\\\]$', '11');",
    // Nitrile
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitrile class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitrile', '^N#C\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Isonitrile', '^[C-]#[N+]\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitrosooxy', '^O=NO\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitro', '^O=[N+]\\\([O-]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Nitroso', '^O=N\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Aldoxime', '^ON=C\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Ketoxime', '^ON=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, '4-pyridyl', '^(C1CC\\\(\\\[\\\*:\\\]\\\)CCN1|C1CNCC\\\(\\\[\\\*:\\\]\\\)C1|C1CCC\\\(\\\[\\\*:\\\]\\\)NC1)$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbamate', '^O=C\\\(O\\\[\\\*:\\\]\\\)N\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Nitrogen groups' AND et2.type = 11
        WHERE et.name IN ('Carboxamide', 'Amidine', 'Amines class', 'Imines class', 'Cyanates class', 'Nitrile class', 'Imide', 'Azide', 'Azo (Diimide)','Nitrate','Nitrosooxy','Nitro','Nitroso','Aldoxime','Ketoxime','4-pyridyl','Carbamate'));",

    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Amines class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Amines class' AND et2.name LIKE 'Nitrogen groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Primary amine', 'Secondary amine', 'Tertiary amine', '4° ammonium ion'));",

    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Imines class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Imines class' AND et2.name LIKE 'Nitrogen groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Primary ketimine', 'Secondary ketimine', 'Primary aldimine', 'Secondary aldimine'));",

    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Cyanates class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Cyanates class' AND et2.name LIKE 'Nitrogen groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Cyanate', 'Isocyanate'));",

    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Nitrile class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Nitrile class' AND et2.name LIKE 'Nitrogen groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Nitrile', 'Isonitrile'));",

    //###########################################
    //########### Sulfur groups #################
    //###########################################

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfhydryl', '^S\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfide', '^S\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Disulfide', '^S\\\(S\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfinyl', '^O=S\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfonyl', '^O=S\\\(=O\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfino', '^O=S\\\(O\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Sulfo', '^(O=S\\\(=O\\\)\\\(O\\\)\\\[\\\*:\\\]|O=S\\\(=O\\\)\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\])$', '11');",
    // Thiocyanate
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Thiocyanate class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Thiocyanate', '^N#CS\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Isothiocynate', '^S=C=N\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbonothioyl', '^(S=C\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]|S=C\\\[\\\*:\\\])$', '11');",
    // Thiocarboxylic acid
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Thiocarboxylic acid class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbothioic S-acid', '^O=C\\\(S\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbothioic O-acid', '^OC\\\(=S\\\)\\\[\\\*:\\\]$', '11');",
    // Thioester
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Thioester class', '', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Thiolester', '^O=C\\\(S\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Thionoester', '^S=C\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbodithioic acid', '^S=C\\\(S\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Carbodithio', '^S=C\\\(S\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    
    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Sulfur groups' AND et2.type = 11
        WHERE et.name IN ('Sulfhydryl', 'Sulfide', 'Disulfide','Sulfinyl','Sulfonyl','Sulfino','Sulfo', 'Thiocyanate class','Carbonothioyl','Thiocarboxylic acid class', 'Thioester class', 'Carbodithioic acid','Carbodithio'))",
    
    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Thiocyanate class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Thiocyanate class' AND et2.name LIKE 'Sulfur groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Thiocyanate', 'Isothiocyanate'));",
    
    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Thiocarboxylic acid class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Thiocarboxylic acid class' AND et2.name LIKE 'Sulfur groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Carbothioic S-acid', 'Carbothioic O-acid'));",
    
    "INSERT INTO `enum_type_links`
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, etl.id as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Thioester class' AND et2.type = 11
        JOIN (SELECT etl.id
            FROM enum_type_links etl
            JOIN enum_types et1 ON et1.id = etl.id_enum_type
            JOIN enum_types et2 ON et2.id = etl.id_enum_type_parent
            WHERE et1.name LIKE 'Thioester class' AND et2.name LIKE 'Sulfur groups') as etl ON etl.id = etl.id
        WHERE et.name IN ('Thiolester', 'Thionoester'));",

    //###########################################
    //########### Phosporus groups ##############
    //###########################################
    
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Phosphino', '^P\\\(\\\[\\\*:\\\]\\\)\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Phosphono', '^O=P\\\(O\\\)\\\(O\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Phosphate', '^(O=P\\\(O\\\)\\\(O\\\)O\\\[\\\*:\\\]|O=P\\\(O\\\)\\\(O\\\[\\\*:\\\]\\\)O\\\[\\\*:\\\])$', '11');",

    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Phosporus groups' AND et2.type = 11
        WHERE et.name IN ('Phosphino', 'Phosphono', 'Phosphate'));",

    //###########################################
    //########### Boron groups ##################
    //###########################################

    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Borono', '^OB\\\(O\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Boronate', '^O\\\(B\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Borino', '^OB\\\(O\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",
    "INSERT INTO `enum_types` (`id`, `name`, `content`, `type`) VALUES (NULL, 'Borinate', '^O\\\(B\\\(\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]\\\)\\\[\\\*:\\\]$', '11');",

    "INSERT INTO `enum_type_links` 
        (SELECT NULL as id, et.id as id_enum_type, et2.id as id_enum_type_parent, NULL as id_enum_type_link, NULL as data, NULL as reg_exp 
        FROM enum_types et
        JOIN enum_types et2 ON et2.name = 'Boron groups' AND et2.type = 11
        WHERE et.name IN ('Borono', 'Boronate', 'Borino', 'Borinate'));",

    "CREATE TABLE `fragments_enum_types` ( `id` INT NOT NULL AUTO_INCREMENT , `id_fragment` INT NOT NULL , `id_enum_type` INT NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;",
    'ALTER TABLE `fragments_enum_types` ADD FOREIGN KEY (`id_enum_type`) REFERENCES `enum_types`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;',
    'ALTER TABLE `fragments_enum_types` ADD FOREIGN KEY (`id_fragment`) REFERENCES `fragments`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;',
    
    "ALTER TABLE `substances_fragments` ADD `total_fragments` TINYINT NOT NULL AFTER `links`;",
    "UPDATE substances_fragments sf
    JOIN (SELECT id_substance, order_number, COUNT(order_number) as total
                    FROM substances_fragments sf
                    GROUP BY id_substance, order_number) as t ON t.id_substance = sf.id_substance AND t.order_number = sf.order_number
    SET sf.total_fragments = t.total;",

    "CREATE TABLE `substance_fragmentation_pairs` (
        `id` int(11) NOT NULL,
        `id_substance_1` int(11) NOT NULL,
        `id_substance_2` int(11) NOT NULL,
        `id_core` int(11) NOT NULL,
        `substance_1_fragmentation_order` smallint(6) DEFAULT NULL,
        `substance_2_fragmentation_order` smallint(6) DEFAULT NULL,
        `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",

      "ALTER TABLE `substance_fragmentation_pairs`
      ADD PRIMARY KEY (`id`),
      ADD KEY `id_substance_1` (`id_substance_1`),
      ADD KEY `id_substance_2` (`id_substance_2`),
      ADD KEY `id_core` (`id_core`);",

      "ALTER TABLE `substance_fragmentation_pairs`
      MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;",

      "ALTER TABLE `substance_fragmentation_pairs`
      ADD CONSTRAINT `substance_fragmentation_pairs_ibfk_1` FOREIGN KEY (`id_substance_1`) REFERENCES `substances` (`id`),
      ADD CONSTRAINT `substance_fragmentation_pairs_ibfk_2` FOREIGN KEY (`id_substance_2`) REFERENCES `substances` (`id`),
      ADD CONSTRAINT `substance_fragmentation_pairs_ibfk_3` FOREIGN KEY (`id_core`) REFERENCES `fragments` (`id`);"
);