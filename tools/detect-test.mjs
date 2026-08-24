/**
 * The corpus behind `assets/js/core/detect.js`.
 *
 *   node tools/detect-test.mjs
 *
 * Nothing else in CourseForge needs Node, and this does not either — it is not
 * a build step and nothing is generated from it. It exists because "recognise
 * the language, but only when fairly sure" is a promise that cannot be checked
 * by reading the code, and a weight nudged upward to catch one more language is
 * exactly the change that starts painting prose as Ruby.
 *
 * Three things are asserted, and the last two matter more than the first:
 *
 *   1. sixty snippets are recognised;
 *   2. twenty-five that are not code in any language — program output, tables,
 *      file trees, ASCII diagrams, one-liners — are declined;
 *   3. a fence that already names the right language is never overruled.
 */
import { detectLanguage, chooseLanguage } from '../assets/js/core/detect.js';
import { isPlain, resolveLanguage, languageLabel } from '../assets/js/core/languages.js';

/* [expected id or null, source] — null means "must decline". */
const CASES = [
  ['javascript', `const users = await fetch('/api/users').then(r => r.json());\nconsole.log(users.length);\nexport default users;`],
  ['javascript', `function greet(name) {\n  if (name === undefined) {\n    return 'hello';\n  }\n  console.log(\`hi \${name}\`);\n}\nmodule.exports = greet;`],
  ['typescript', `interface User {\n  id: number;\n  name: string;\n  email?: string;\n}\n\nexport function findUser(id: number): User | undefined {\n  return users.find(u => u.id === id);\n}`],
  ['typescript', `@Injectable({ providedIn: 'root' })\nexport class AuthService {\n  private token: string | null = null;\n  constructor(private http: HttpClient) {}\n}`],
  ['python', `def fibonacci(n):\n    if n <= 1:\n        return n\n    return fibonacci(n - 1) + fibonacci(n - 2)\n\nprint(fibonacci(10))`],
  ['python', `import pandas as pd\n\nclass Report:\n    def __init__(self, path):\n        self.frame = pd.read_csv(path)\n\n    def total(self):\n        return self.frame['amount'].sum()`],
  ['ruby', `class Order\n  attr_accessor :items\n\n  def initialize(items)\n    @items = items\n  end\n\n  def total\n    items.map { |i| i.price }.sum\n  end\nend`],
  ['ruby', `require 'json'\n\nusers.each do |user|\n  puts "#{user.name}: #{user.email}"\nend`],
  ['java', `package com.example.demo;\n\nimport java.util.List;\n\npublic class Application {\n    public static void main(String[] args) {\n        System.out.println("Hello");\n    }\n}`],
  ['csharp', `using System;\nusing System.Collections.Generic;\n\nnamespace Demo\n{\n    public class Person\n    {\n        public string Name { get; set; }\n    }\n}`],
  ['cpp', `#include <iostream>\n#include <vector>\n\nint main() {\n    std::vector<int> numbers{1, 2, 3};\n    for (auto n : numbers) std::cout << n << "\\n";\n    return 0;\n}`],
  ['c', `#include <stdio.h>\n#include <stdlib.h>\n\nint main(void) {\n    char *buffer = malloc(64);\n    printf("%s\\n", buffer);\n    free(buffer);\n    return 0;\n}`],
  ['go', `package main\n\nimport (\n\t"fmt"\n)\n\nfunc main() {\n\tdata, err := load()\n\tif err != nil {\n\t\tpanic(err)\n\t}\n\tfmt.Println(data)\n}`],
  ['rust', `use std::collections::HashMap;\n\n#[derive(Debug)]\npub struct Config {\n    name: String,\n}\n\nfn main() {\n    let mut map = HashMap::new();\n    println!("{:?}", map);\n}`],
  ['php', `<?php\n\nnamespace App\\\\Http;\n\nclass Controller\n{\n    public function index()\n    {\n        return view('home');\n    }\n}`],
  ['php', `class Cart\n{\n    private array $items = [];\n\n    public function add(string $sku): void\n    {\n        $this->items[] = $sku;\n    }\n}`],
  ['shellscript', `#!/bin/bash\nset -e\nfor file in *.txt; do\n  echo "processing $file"\ndone`],
  ['shellscript', `npm install --save-dev vite\nnpm run build\ngit add -A`],
  ['shellscript', `if [ -f config.json ]; then\n  cp config.json config.backup.json\nfi`],
  ['powershell', `$files = Get-ChildItem -Path C:\\\\Logs -Filter *.log\n$files | Where-Object { $_.Length -gt 1024 } | Remove-Item`],
  ['sql', `SELECT u.id, u.name, COUNT(o.id) AS orders\nFROM users u\nLEFT JOIN orders o ON o.user_id = u.id\nWHERE u.active = 1\nGROUP BY u.id\nORDER BY orders DESC;`],
  ['sql', `CREATE TABLE products (\n  id INTEGER PRIMARY KEY,\n  name VARCHAR(255) NOT NULL,\n  price DECIMAL(10,2)\n);`],
  ['json', `{\n  "name": "courseforge",\n  "version": "3.1.0",\n  "scripts": { "build": "vite build" },\n  "private": true\n}`],
  ['yaml', `version: "3.9"\nservices:\n  web:\n    image: nginx:alpine\n    ports:\n      - "80:80"\n    environment:\n      - NODE_ENV=production`],
  ['toml', `[package]\nname = "demo"\nversion = "0.1.0"\nedition = "2021"\n\n[dependencies]\nserde = { version = "1.0", features = ["derive"] }`],
  ['html', `<!DOCTYPE html>\n<html lang="en">\n<head>\n  <meta charset="utf-8">\n  <title>Demo</title>\n</head>\n<body>\n  <div class="wrapper"><p>Hello</p></div>\n</body>\n</html>`],
  ['html', `<section class="hero">\n  <h1>Welcome</h1>\n  <p>Some text</p>\n  <a href="/start">Start</a>\n</section>`],
  ['css', `.card {\n  display: flex;\n  padding: 16px;\n  border-radius: 8px;\n  background: #fff;\n}\n\n@media (max-width: 600px) {\n  .card { padding: 8px; }\n}`],
  ['scss', `$primary: #38bdf8;\n\n.card {\n  background: $primary;\n\n  &:hover {\n    background: darken($primary, 10%);\n  }\n\n  @include shadow(2);\n}`],
  ['xml', `<?xml version="1.0" encoding="UTF-8"?>\n<catalog>\n  <book id="1"><title>Demo</title></book>\n</catalog>`],
  ['docker', `FROM node:20-alpine\nWORKDIR /app\nCOPY package*.json ./\nRUN npm ci --omit=dev\nCOPY . .\nEXPOSE 3000\nCMD ["node", "server.js"]`],
  ['make', `.PHONY: build test\n\nbuild:\n\tgo build -o bin/app ./cmd/app\n\ntest:\n\tgo test ./...`],
  ['nginx', `server {\n  listen 80;\n  server_name example.com;\n\n  location /api/ {\n    proxy_pass http://127.0.0.1:3000;\n  }\n}`],
  ['apache', `<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteCond %{REQUEST_FILENAME} !-f\n  RewriteRule ^(.*)$ index.php [QSA,L]\n</IfModule>`],
  ['http', `POST /api/v1/tokens HTTP/1.1\nHost: api.example.com\nContent-Type: application/json\nAuthorization: Bearer abc123`],
  ['graphql', `query GetUser($id: ID!) {\n  user(id: $id) {\n    id\n    name\n    posts {\n      title\n    }\n  }\n}`],
  ['proto', `syntax = "proto3";\n\npackage demo;\n\nmessage User {\n  string id = 1;\n  string name = 2;\n}`],
  ['kotlin', `data class User(val id: Long, val name: String)\n\nfun main() {\n    val users = listOf(User(1, "Ada"))\n    println(users.first().name)\n}`],
  ['swift', `import SwiftUI\n\nstruct ContentView: View {\n    @State private var count = 0\n\n    var body: some View {\n        Button("Tap") { count += 1 }\n    }\n}`],
  ['dart', `import 'package:flutter/material.dart';\n\nclass HomePage extends StatelessWidget {\n  @override\n  Widget build(BuildContext context) {\n    return Scaffold(body: Text('Hi'));\n  }\n}`],
  ['elixir', `defmodule Demo.Accounts do\n  alias Demo.Repo\n\n  def list_users do\n    User\n    |> Repo.all()\n  end\nend`],
  ['haskell', `module Main where\n\nimport Data.List (sort)\n\nfactorial :: Integer -> Integer\nfactorial n = product [1..n]`],
  ['lua', `local M = {}\n\nfunction M.greet(name)\n  if name == nil then\n    return "hello"\n  end\n  return "hello " .. name\nend\n\nreturn M`],
  ['perl', `#!/usr/bin/perl\nuse strict;\nuse warnings;\n\nmy @lines = <STDIN>;\nforeach my $line (@lines) {\n    print $line;\n}`],
  ['r', `library(dplyr)\n\nresult <- data %>%\n  filter(age > 30) %>%\n  summarise(mean_income = mean(income))\n\nprint(result)`],
  ['matlab', `function [y] = smooth(x, w)\n  y = zeros(size(x));\n  for i = 1:numel(x)\n    y(i) = mean(x(max(1,i-w):i));\n  end\nend`],
  ['objective-c', `#import <Foundation/Foundation.h>\n\n@interface Person : NSObject\n@property (nonatomic, strong) NSString *name;\n@end`],
  ['clojure', `(ns demo.core\n  (:require [clojure.string :as str]))\n\n(defn greet [name]\n  (println (str "hello " name)))`],
  ['solidity', `pragma solidity ^0.8.0;\n\ncontract Token {\n    mapping(address => uint256) public balances;\n\n    function transfer(address to, uint256 amount) public {\n        balances[msg.sender] -= amount;\n    }\n}`],
  ['terraform', `resource "aws_s3_bucket" "assets" {\n  bucket = "my-assets"\n  acl    = "private"\n}\n\nvariable "region" {\n  default = "eu-central-1"\n}`],
  ['diff', `diff --git a/app.js b/app.js\n--- a/app.js\n+++ b/app.js\n@@ -1,4 +1,4 @@\n-const a = 1;\n+const a = 2;`],
  ['markdown', `# Getting started\n\nInstall the package:\n\n\`\`\`\nnpm i demo\n\`\`\`\n\n- one\n- two\n\nSee the [docs](https://example.com).`],
  ['latex', `\\documentclass{article}\n\\usepackage{amsmath}\n\n\\begin{document}\n\\section{Intro}\nHello\n\\end{document}`],
  ['gherkin', `Feature: Login\n\n  Scenario: Valid credentials\n    Given a registered user\n    When they submit the form\n    Then they reach the dashboard`],
  ['ini', `[server]\nhost = localhost\nport = 8080\n\n; connection settings\n[database]\nname = demo`],
  ['bat', `@echo off\nset TARGET=build\nif exist %TARGET% rmdir /s /q %TARGET%\ngoto :eof`],
  ['vue', `<template>\n  <div class="app">\n    <button @click="increment">{{ count }}</button>\n  </div>\n</template>\n\n<script setup>\nimport { ref } from 'vue'\nconst count = ref(0)\n</script>`],
  ['asm', `section .text\nglobal _start\n\n_start:\n    mov eax, 4\n    mov ebx, 1\n    int 0x80`],
  ['scala', `case class User(id: Long, name: String)\n\nobject Main extends App {\n  val users: List[User] = List(User(1, "Ada"))\n  users.foreach(u => println(u.name))\n}`],
  ['groovy', `plugins {\n    id 'java'\n}\n\ndependencies {\n    implementation 'org.springframework.boot:spring-boot-starter-web'\n}`],

  /* ---- must decline -------------------------------------------------- */
  [null, `Hello world`],
  [null, `This chapter explains how variables work and why they matter when you are\nwriting your first program. Read it before moving on.`],
  [null, `Name        Age   City\nAlice        31   Berlin\nBob          27   Vienna`],
  [null, `project/\n├── src/\n│   ├── index.js\n│   └── app.js\n└── package.json`],
  [null, `Error: connection refused\n  at Socket.connect (net.js:1141:16)\n  at process._tickCallback (internal/process/next_tick.js:63:19)`],
  [null, `1. Open the settings panel\n2. Choose "Advanced"\n3. Restart the service`],
  [null, `+---------+       +---------+\n| Client  | ----> | Server  |\n+---------+       +---------+`],
  [null, `x = 1`],
  [null, `foo bar baz`],
  [null, `[INFO] Build succeeded in 4.2s\n[WARN] 2 deprecations found\n[INFO] Done.`],
  [null, `Input:  [3, 1, 2]\nOutput: [1, 2, 3]`],
  [null, `A -> B -> C\nB -> D`],
  [null, `total 24\ndrwxr-xr-x  4 user staff  128 Jan  3 11:02 .\n-rw-r--r--  1 user staff  931 Jan  3 11:02 README.md`],
  [null, `GET /users        list every user\nPOST /users       create one\nDELETE /users/:id remove one`],
  [null, `O(n log n)`],
  [null, `user.name\nuser.email\nuser.created_at`],
  [null, `TODO: rewrite this section once the API is stable.`],
  [null, `┌────────┐\n│ header │\n└────────┘`],
  [null, `key: value`],
  [null, `git`],
  [null, `Chapter 1 — Introduction\nChapter 2 — Installation\nChapter 3 — First steps`],
  [null, `if (condition) {\n  doSomething();\n}`],
  [null, `let x = 5`],
  [null, `<b>bold</b>`],
  [null, `2024-01-03 11:02:41 INFO  Starting server on port 8080\n2024-01-03 11:02:42 INFO  Ready`],
];

let pass = 0;
const failures = [];

for (const [expected, source] of CASES) {
  const got = detectLanguage(source);
  const id = got?.id ?? null;
  if (id === expected) pass += 1;
  else failures.push({ expected, id, score: got?.score, sure: got?.sure, source: source.split('\n')[0].slice(0, 58) });
}

console.log(`detection: ${pass}/${CASES.length}`);
if (failures.length) {
  console.log('\nmisses:');
  for (const f of failures) {
    console.log(`  want ${String(f.expected).padEnd(13)} got ${String(f.id).padEnd(13)} score ${f.score ?? '-'}  | ${f.source}`);
  }
}

/* ---- overriding a fence that named something ------------------------- */
const OVERRIDES = [
  // [declared, plain, source, expected id, expected "was overruled"]
  ['python', false, `{"a": 1, "b": [2, 3]}`, 'json', true],
  ['python', false, `def add(a, b):\n    return a + b`, 'python', false],
  ['javascript', false, `SELECT u.id, u.name, COUNT(o.id) AS orders\nFROM users u\nLEFT JOIN orders o ON o.user_id = u.id\nWHERE u.active = 1\nGROUP BY u.id;`, 'sql', true],
  // one line of SQL is not enough to overrule a fence that named a language
  ['javascript', false, `SELECT id FROM users;`, 'javascript', false],
  ['javascript', false, `const x = 1;\nconsole.log(x);`, 'javascript', false],
  ['ruby', false, `def add(a, b)\n  a + b\nend`, 'ruby', false],
  ['sql', false, `SELECT * FROM t;`, 'sql', false],
  [null, true, `def fibonacci(n):\n    if n <= 1:\n        return n\n    return fibonacci(n - 1) + fibonacci(n - 2)\n\nprint(fibonacci(10))`, 'python', false],
  [null, true, `Error: connection refused\n  at Socket.connect (net.js:1141:16)`, null, false],
  [null, true, `npm install\nnpm run build`, null, false],
  [null, false, `npm install --save-dev vite\nnpm run build\ngit add -A`, 'shellscript', false],
];

let opass = 0;
const ofail = [];
for (const [declared, plain, source, wantId, wantReplaced] of OVERRIDES) {
  const out = chooseLanguage(source, declared, { plain });
  const ok = out.id === wantId && Boolean(out.replaced) === wantReplaced;
  if (ok) opass += 1;
  else ofail.push({ declared, plain, wantId, wantReplaced, out, head: source.split('\n')[0].slice(0, 44) });
}
console.log(`\nfence handling: ${opass}/${OVERRIDES.length}`);
for (const f of ofail) {
  console.log(`  declared=${f.declared} plain=${f.plain} want=${f.wantId}/${f.wantReplaced} got=${f.out.id}/${f.out.replaced} | ${f.head}`);
}

/* ---- what the fence itself is taken to mean -------------------------- */

const READINGS = [
  // [info string, is it an explicit "leave this alone", what it resolves to]
  ['', false, null],              // silence is not a decision — detection fills it in
  ['   ', false, null],
  ['text', true, null],           // this one is a decision, and is respected
  ['plaintext', true, null],
  ['output', true, null],
  ['ts', false, 'typescript'],
  ['C#', false, 'csharp'],
  ['golang', false, 'go'],
  ['cmd', false, 'bat'],          // Shiki gives this to Visual Basic; we do not
  ['js title="app.js"', false, 'javascript'],
  ['{.python}', false, 'python'],
  ['language-rust', false, 'rust'],
  ['yaml:docker-compose.yml', false, 'yaml'],
  ['pseudocode', false, null],    // named, but nobody has a grammar for it
];

let rpass = 0;
const rfail = [];
for (const [info, plain, id] of READINGS) {
  const got = { plain: isPlain(info), id: resolveLanguage(info) };
  if (got.plain === plain && got.id === id) rpass += 1;
  else rfail.push(`"${info}" → plain=${got.plain} id=${got.id}, wanted plain=${plain} id=${id}`);
}
console.log(`\nfence readings: ${rpass}/${READINGS.length}`);
rfail.forEach((f) => console.log('  ' + f));

if (languageLabel('shellscript') !== 'Shell') rfail.push('languageLabel(shellscript) is not "Shell"');

/* ---- a correct fence is never overruled ------------------------------ */
let kept = 0;
const stolen = [];
for (const [expected, source] of CASES) {
  if (!expected) continue;
  const out = chooseLanguage(source, expected);
  if (out.id === expected && !out.replaced) kept += 1;
  else stolen.push(`${expected} → ${out.id}`);
}
console.log(`\ncorrect fences kept: ${kept}/${CASES.filter(([e]) => e).length}`);
stolen.forEach((s) => console.log('  ' + s));

process.exit(failures.length || ofail.length || rfail.length || stolen.length ? 1 : 0);
