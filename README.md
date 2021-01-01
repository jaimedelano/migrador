# O QUE FAZ?
Copia todas as contas cPanel de uma revenda usando o login e senha do revendedor

# Copiar e restaurar todas as contas:
php migrador.php IP REVENDEDOR SENHA

# Apenas listar as contas:
php migrador.php IP REVENDEDOR SENHA -l

# Apenas copiar e não restaurar:
php migrador.php IP REVENDEDOR SENHA -b

# Copiar apenas duas contas:
php migrador.php IP REVENDEDOR SENHA -i conta1,conta2

# Não copiar algumas contas:
php migrador.php IP REVENDEDOR SENHA -e conta1,conta2
