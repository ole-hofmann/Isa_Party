<?php
// Kopieren nach config.php und anpassen. config.php wird nicht ins Git-Repo eingecheckt.
return [
    // Passwort für den Einlass (alle Handys am Eingang nutzen dasselbe)
    'password' => 'bitte-aendern',

    // Eintritt in Euro
    'price' => 5,

    // Gästeliste: ein Name pro Zeile
    'csv' => __DIR__ . '/data/teilnehmerinnen.csv',

    'title' => 'Grusel-Einlass',
];
