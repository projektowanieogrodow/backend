# TODO API - Menadżer Zadań

**Autor:** Emilia
**Grupa:** INMN2(hybryda)_PAW2
**Data:** 15.11.2025

## Opis projektu
REST API dla menadżera zadań z zapisem do pliku JSON.

## Technologie
- PHP
- JSON (do przechowywania danych)

## Instalacja i uruchomienie

### Wymagania
- Serwer XAMPP Apache
- [inne wymagania]

### Krok po kroku
```bash
# 1. Sklonuj repozytorium
git clone https://github.com/projektowanieogrodow/backend.git

# 2. Przejdź do katalogu servera
cd cd C:\xampp

# 3. Uruchom serwer
xampp_start.exe


## Endpointy
1. GET /health - status API
2. GET /tasks - pobranie danych
3. POST /tasks - dodanie danych
4. PUT /tasks/1 - edycja danych dla id = 1
5. DELETE /tasks/1 - usunięcie danych dla id = 1