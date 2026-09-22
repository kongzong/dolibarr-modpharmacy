# modPharmacy — Dolibarr 药房发药模块

Dolibarr 22.0.x 外部模块：面向中医馆/中西医结合诊所的处方发药与效期预警。零 core 修改。
医疗模块群二期第一个模块，依赖 [modPatient](https://github.com/kongzong/dolibarr-modpatient) ≥ 0.1.3、
[modPrescription](https://github.com/kongzong/dolibarr-modprescription) ≥ 0.1.1 与原生 product/stock/productbatch。

当前版本：**0.1.0（阶段 1 骨架，未入 git）**。规格见 [docs/spec-pharmacy-v0.1.md](docs/spec-pharmacy-v0.1.md)（已评审通过，2026-09-22）。

## 设计要点

- 一张发药单 = 对一张**已签发**处方的执行：**待发药 → 已发药 → 已退回**；永不物理删除
- **确认发药幂等**：条件 UPDATE（status 0→1）做闸门，重复点击/并发只扣一次；扣减与处方状态推进同事务，任何失败整体回滚（fail-closed，不部分发药）
- **FEFO 强制**：批次产品按效期先出（`Productbatch::findAllForProduct` 按 sellby 排序），不允许拣选批次；批次/效期写入行快照
- 处方状态只经 modPrescription 0.1.1 的 `markDispensed()` / `markDispenseUndone()` 桥接推进，不直写处方表
- **退回**：必填原因，按原批次逆向入库（`MouvementStock::reception`），处方回已签发可重开
- 效期预警页（近效期/过期批次清单）+ 每日 Cron（默认关）；**wecom 推送默认关**，开启属维护者显式决定（对外触达红线）
- 权限一级形式 `read / write / dispense / return / admin`；审计复用 `llx_patient_audit`，动作 `PHARMACY_*`
- 编号 `FY-YYYYMMDD-NNN` 按日流水，取号与保存同事务（行锁方案，与 patient/medrecord/prescription 同构）
- 模块 ID `501630`；左菜单挂 modPatient 的"诊所"顶级菜单

## 阶段状态

| 阶段 | 内容 | 状态 |
|---|---|---|
| 0 modPrescription 0.1.1 | `markDispensed` / `markDispenseUndone` 桥接方法 + `date_dispensed` 列 | 已发布 `v0.1.1`（2026-09-22） |
| 1 骨架 | descriptor（ID 501630、models=1、hooks=prescriptioncard、cron 默认关）、2 表 + sequence、常量、权限、菜单（发药列表/效期预警）、`Dispense` 类 fetch/search 壳、`PharmacyExpiryAlert` 扫描、`PharmacyNumbering`、页面壳（card/list/expiry/setup）、双语言、8 单元测试 | 代码完成：8 单元 + 6 集成（MariaDB 20 并发）通过 |
| 2 发药核心 | `Dispense::create`（从处方建单，唯一性闸门）/ `confirm`（幂等 + FEFO 扣减）/ `return`（逆向）；处方页 hook 注入；确认/退回按钮 | 待做 |
| 3 PDF 与效期 | `pdf_fy` 发药单 PDF、确认自动生成、效期推送（wecom，开关） | 待做 |
| 4 集成面 | REST 6 端点（API 类 `Pharmacy`）、停用→启用全流程、`v0.1.0` | 待做 |

## 测试

```
php tests/run_all.php                 # 结构 + 编号行为测试，无需数据库
php tests/integration/numbering.php   # 真实 MariaDB：20 并发取号、锁交接、回滚释放、缺表失败（隔离临时表）
```

## 安装

```
git clone https://github.com/kongzong/dolibarr-modpharmacy htdocs/custom/pharmacy
```
先启用 modPatient 与 modPrescription（≥ 0.1.1），再启用 Pharmacy（需原生 product/stock/productbatch 已启用）。
停用只移除常量/权限/菜单/hook，不删任何表、发药单或 PDF。

## 开发约定

遵循 [custom/DOLIBARR-MODULE-DEVELOPMENT.md](../DOLIBARR-MODULE-DEVELOPMENT.md)；环境与模块状态见 [custom/AGENTS.md](../AGENTS.md)。
