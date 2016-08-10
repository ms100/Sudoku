<?php
include './Sudoku.php';
$obj = new Sudoku(file_get_contents('./sudoku2.txt'));
$obj->run();