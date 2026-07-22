import re
def only_digits(s: str) -> str: return re.sub(r'\D', '', s or '')
def validate_cpf(cpf: str) -> bool:
    cpf = only_digits(cpf)
    if (not cpf) or (len(cpf) != 11) or (cpf == cpf[0]*11): return False
    s1 = sum(int(cpf[i])*(10-i) for i in range(9)); d1 = 11 - (s1 % 11); d1 = 0 if d1 >= 10 else d1
    s2 = sum(int(cpf[i])*(11-i) for i in range(10)); d2 = 11 - (s2 % 11); d2 = 0 if d2 >= 10 else d2
    return cpf[-2:] == f"{d1}{d2}"
def validate_cnpj(cnpj: str) -> bool:
    cnpj = only_digits(cnpj)
    if (not cnpj) or (len(cnpj) != 14) or (cnpj == cnpj[0]*14): return False
    def calc(nums, w): 
        r = sum(int(n)*ww for n, ww in zip(nums, w)) % 11
        return '0' if r < 2 else str(11 - r)
    d1 = calc(cnpj[:12], [5,4,3,2,9,8,7,6,5,4,3,2])
    d2 = calc(cnpj[:12]+d1, [6,5,4,3,2,9,8,7,6,5,4,3,2])
    return cnpj[-2:] == d1 + d2
def is_cpf_cnpj_valid(v: str) -> bool:
    d = only_digits(v)
    if len(d)==11: return validate_cpf(d)
    if len(d)==14: return validate_cnpj(d)
    return False
