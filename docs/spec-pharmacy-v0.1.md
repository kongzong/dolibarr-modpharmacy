# modPharmacy V0.1 规格 — 发药与效期

> 状态：**已评审通过，动工中**（评审通过 2026-09-22）。医疗模块群二期第一个模块（规划稿 §3.5，清单 #5）。
> 依赖 modPatient ≥ 0.1.3（审计/摘要/上下文条）、modPrescription ≥ 0.1.1（V0.1 前置改动，见 §2）；
> 库存与批次用 Dolibarr 原生 product/stock + productbatch，零 core 修改。
> 上位规划：`custom/HEALTHCARE-MODULES-PLAN.md` §3.5；技术约定：`custom/DOLIBARR-MODULE-DEVELOPMENT.md`。

## 0. 相对规划稿的调整

| 项 | 规划稿 | 本规格 | 理由 |
|---|---|---|---|
| 处方审核步骤 | "处方审核→发药" 两态 | 发药单**草稿（待发药）→ 已发药**两步：草稿不扣库存，确认时才扣 | 审核动作落在"草稿→确认"上，与处方签发（医生侧授权）衔接；也天然支持"先配药后备货" |
| 状态机 | "已开方→已审核→已发药" | 处方侧仍只有 草稿/已签发/**已发药(2)**/已作废；"已审核"不新增处方状态 | 处方签发即医生授权，再加一层审核状态会让处方状态机复杂化；药房的审核体现在发药单上 |
| 发药对象 | 处方 | 自有对象**发药单**（一个处方一张，退回后可重开） | 库存扣减、批次分配、退回都需要可追溯的单据载体 |
| TCM 饮片扣库存 | 未定 | 开放项 §8.3（默认：与 WM 同规则，有 `fk_product` 就扣，自由文本行不扣） | 单位一致性靠产品建档约束，不做换算 |
| 效期预警推送 | "推送库管（wecom 消息）" | 默认只在**预警页**展示；wecom 推送为常量开关、默认关 | 对外触达红线：启用推送 = 维护者显式决定 |

## 1. 定位与已核实地基

一张发药单 = 药房对一张**已签发**处方的执行记录：逐行从指定仓库扣减库存（批次产品按 FEFO 先效期先出），
生成发药单 PDF，并把处方状态推进到"已发药"。退回（发错药/患者退药）做逆向库存并允许重开。

本机 22.0.4 源码已核实（2026-09-22）：

| 依赖 | 出处 | 结论 |
|---|---|---|
| 库存扣减 | `mouvementstock.class.php:858` `MouvementStock::livraison($user, $fk_product, $entrepot_id, $qty, $price, $label, $datem, $eatby, $sellby, $batch, ...)` → `_create(..., 0-$qty, type 2, ...)` | 逐行调用；批次产品在 `$conf->productbatch->enabled` 时自动走批次路径 |
| 批次查询/FEFO | `productbatch.class.php:583` `Productbatch::findAllForProduct($fk_product, $fk_warehouse, $qty_min, $sortfield, $sortorder)` 返回 batch/eatby/sellby/qty | FEFO = `sortfield='pl.sellby', sortorder='ASC'`（空值行排最后由实现过滤）；不足即整单失败 |
| 批次表 | `llx_product_lot`（eatby/sellby）+ `llx_product_batch`（fk_product_stock, batch, qty；UNIQUE(fk_product_stock, batch)，`llx_product_batch.key.sql:23`） | 原生维护，本模块只读批次、只经 MouvementStock 写 |
| 处方状态预留 | `prescription.lib.php:31` `PRESCRIPTION_STATUS_DISPENSED=2`；`PrescriptionSheet::void()` 已排除状态 2 | 药房通过 §2 的 `markDispensed()` 写入，不直接 UPDATE 处方表 |
| 发药单 PDF | 与 modPrescription 同款：`models=1`、`core/modules/pharmacy/doc/pdf_fy.modules.php`、`stsongstdlight`、`$conf->pharmacy->dir_output` | 复用 chinadoc/prescription 已验证结论（models 必须标量 1） |
| 审计 | `patient_audit($db, $fkPatient, $action, $user, $details)`；审计页动作筛选从表动态取（patient 0.1.2） | 动作前缀 `PHARMACY_*`（自由字符串，页自动收录） |
| 编号 | 复制 patient/medrecord/prescription 的行锁方案 | `FY-YYYYMMDD-NNN`，历史号回填 |
| Cron | `modWeCom.class.php:138` descriptor `$this->cronjobs` 数组（class/method/frequency/status） | 效期预警每日一跑，默认 `status=0` 关闭 |
| REST | 见 prescription spec §3.7 "REST 命名约束" | URL `/pharmacy/...` → API 类 `Pharmacy`；业务类不得与之只差大小写 |

## 2. 前置改动（独立提交，modPrescription 0.1.1）

`PrescriptionSheet` 新增两个方法（状态推进与审计在处方侧闭环，药房只调用不直写）：

- `markDispensed(User $user)`：`已签发(1) → 已发药(2)`，仅此一跃；写审计 `PRESCRIPTION_DISPENSE`（含发药单号）
- `markDispenseUndone(User $user)`：`已发药(2) → 已签发(1)`，供退回流程调用；写审计 `PRESCRIPTION_DISPENSE_UNDONE`（含原因）

两方法不检查 prescription 权限（授权在药房侧），只校验状态合法性与行级锁（`WHERE status=?` 乐观锁）。
无表结构变更、不需重启用（无 hooks/tabs 变更）。处方页状态徽章与列表已支持状态 2，无需改动。

## 3. 功能范围（做）

### 3.1 数据

- `llx_pharmacy_dispense`：`rowid, entity, ref(唯一 FY-YYYYMMDD-NNN), fk_prescription(唯一约束：同一处方同时最多一张未退回发药单), fk_patient, fk_warehouse, status(0待发药/1已发药/9已退回), date_dispense, fk_user_dispense, return_reason(退回原因), note, model_pdf, fk_user_creat, date_creation, tms`
- `llx_pharmacy_dispense_line`：`rowid, fk_dispense, fk_prescription_line, position, fk_product(可空：自由文本行), product_ref, label(快照), qty(decimal 10,3), qty_unit, is_stock(1=已扣库存/0=非库存行), batch_note(发药批号快照，发药时写入 "批次/效期" 逗号串)`
- `llx_pharmacy_dispense_sequence`：`ref_prefix PK, last_value`（与 prescription 同构）
- 常量：`PHARMACY_WAREHOUSE_ID`（默认 0=发药时选择；设值则默认锁定）、`PHARMACY_EXPIRY_DAYS`（预警窗口，默认 90）、`PHARMACY_EXPIRY_NOTIFY`（0 关/1 开 wecom 推送）

### 3.2 编号

`FY-YYYYMMDD-NNN` 按日流水，取号在 create 事务内；复制 `PrescriptionNumbering`，历史号从 `llx_pharmacy_dispense.ref` 回填。

### 3.3 发药流程（红线：幂等）

1. **创建草稿**：从处方页"发药"按钮（或列表/REST）创建，校验处方 `status=1 已签发`；快照处方明细；`fk_warehouse` 取常量或选择。草稿不碰库存。
2. **确认发药**（唯一扣库存点，单事务）：
   - 行级校验：处方仍为已签发（`SELECT ... FOR UPDATE` 锁处方行，防并发签发/作废）
   - 幂等闸门：发药单 `UPDATE ... SET status=1 WHERE rowid=? AND status=0`，影响行数 ≠ 1 → 拒绝（重复提交、并发点击天然幂等）
   - 逐行扣减：`is_stock` 行按 FEFO 分配批次（`findAllForProduct` 按 sellby ASC），逐批 `MouvementStock::livraison()`（label=`发药 {ref}`，批次/效期写入 `batch_note`）；任一行库存不足 → **整事务回滚**（fail-closed，不部分发药）
   - 全部成功 → `PrescriptionSheet::markDispensed()`；写审计 `PHARMACY_DISPENSE`（含逐行批次明细）
   - 自动生成发药单 PDF
3. **退回**（状态 1 → 9）：必填原因；同事务内按 `batch_note` 逆向入库（`MouvementStock::reception()`，label=`退药 {ref}`）→ `markDispenseUndone()` → 审计 `PHARMACY_RETURN`。退回后处方回到已签发，可重新开发药单。

### 3.4 状态机与权限

| 动作 | 从 → 到 | 权限 | 条件 |
|---|---|---|---|
| 创建发药单草稿 | — → 0 | `write` | 处方已签发；同一处方无未退回的发药单 |
| 确认发药 | 0 → 1 | `dispense` | 库存足够（不足整单失败）；处方仍已签发 |
| 退回 | 1 → 9 | `return` | 必填原因；逆向入库成功后才改状态 |
| 打印 PDF | 任意 | `read` | 写 `PHARMACY_PRINT` 审计 |
| 阅读/列表 | — | `read` | 打开发药页写 `PHARMACY_READ` |

权限一级形式：`read / write / dispense / return / admin`。**库存扣减与状态推进在同一事务，任何失败整体回滚；发药单永不物理删除。**

### 3.5 页面

- 左菜单（`fk_mainmenu=clinic`）：发药列表、效期预警
- **发药页** `pharmacy/card.php`：处方摘要（患者横幅/编号/类型/医生）+ 明细表（药名/数量/批次分配预览/是否库存行）+ 确认发药/退回按钮；草稿可改仓库与行数量（数量上限 = 处方行数量，可少发？——开放项 §8.4）
- **列表** `pharmacy/list.php`：编号/处方号/卡号/姓名、仓库、状态、日期区间
- **处方页注入**（hook `prescriptioncard`，modPrescription 0.1.1 一并加 `executeHooks`）：已签发处方上显示"发药"按钮 + 该处方发药单列表
- **效期预警页** `pharmacy/expiry.php`：`PHARMACY_EXPIRY_DAYS` 窗口内近效期与已过期批次清单（产品/批次/效期/余量/仓库），按效期升序；`PHARMACY_EXPIRY_NOTIFY=1` 时 Cron 推送摘要
- **设置页**：仓库常量、预警窗口、推送开关（含说明：开启即同意向配置的 wecom 用户发送内部通知）

### 3.6 发药单 PDF

- `pdf_fy`（A4 纵向）：机构名/"发药单"/编号、患者姓名/卡号、处方编号、发药时间、发药人；正文表格 序号/药品/数量/单位/批次/效期/备注（非库存行标注"非库存"）；底部 发药人/审核/领药人 签名位
- 中文 `stsongstdlight`；超页换页重复表头；退回单不单独出版式（退回在处方笺状态体现 + 审计）

### 3.7 Cron 与 REST

- Cron（默认关）：每日 `PharmacyExpiryAlert::doScheduledJob`——扫描近效期/过期批次，写预警页数据源；`PHARMACY_EXPIRY_NOTIFY=1` 时经 wecom 发给配置的内部用户
- REST（API 类 `Pharmacy`；业务类 `Dispense`/`DispenseLine`，命名按 REST 约束让位）：
  - `GET pharmacy/dispenses?patient=&prescription=&status=&from=&to=`（read）
  - `GET pharmacy/dispenses/{id}`（read，写 PHARMACY_READ；含明细与批次）
  - `POST pharmacy/dispenses`（write；body `{prescription, warehouse?, lines?}`；处方非已签发 → 409）
  - `POST pharmacy/dispenses/{id}/confirm`（dispense；库存不足 → 409 附缺口明细；重复确认 → 幂等返回当前状态）
  - `POST pharmacy/dispenses/{id}/return`（return；`{reason}`）
  - `GET pharmacy/expiry?days=&warehouse=`（read；效期清单）
  - 库存本身不另开端点（原生 stock API 已有）

## 4. 明确不做（V0.1）

- 药库/药房多级调拨、供应商 GSP 质量档案（规划稿红线）
- 库存盘点、采购入库、进价管理（原生或后续立项）
- 医保/处方向外流转；TCM 代煎流程管理（代煎仅作处方上的标注）
- 处方"部分发药"拆单（V0.2 候选，见开放项 §8.4）
- PostgreSQL

## 5. 红线

1. **发药幂等**：确认发药以 `status 0→1` 条件更新为闸门，重复提交/并发只扣一次；扣减与状态推进同事务，失败整体回滚
2. **处方状态机不可跳转**：药房只经 `markDispensed()/markDispenseUndone()` 推进处方状态，不直写处方表；已作废/草稿处方不可发药
3. **库存不足 fail-closed**：不部分发药、不发负库存、不静默缺货
4. 发药单**不可物理删除**；退回必填原因并留逆向库存记录；全流程审计 `PHARMACY_CREATE/DISPENSE/RETURN/READ/PRINT`
5. FEFO 强制：批次产品必须按效期先后分配，不允许拣选批次（V0.1）
6. 发药页、PDF、REST 永不输出患者证件号
7. 零 core 修改；API 先 grep；`models` 标量 1；效期推送默认关，开启属维护者显式决定
8. 编号取号与保存同事务，失败整体回滚；20 并发不重号

## 6. 阶段划分

| 阶段 | 交付 | 人工验证 |
|---|---|---|
| 0 modPrescription 0.1.1 | §2 两方法 + 版本 + 标签 + 单测 | 发药状态推进/回退正确；REST 不受影响 |
| 1 骨架 | descriptor（ID 501630、models=1、hooks、cron）、2 表 + sequence、常量、权限、菜单、语言、测试运行器 | UI 启用；"诊所"菜单出现发药入口；设置页常量 |
| 2 发药核心 | `Dispense` 类（create 取号/confirm 幂等扣减/return 逆向）、`PharmacyNumbering` + 单元与 20 并发集成测试、FEFO 分配器、发药页/列表/处方页 hook | 从处方发药全流程；FEFO 顺序正确；库存不足整单回滚；重复点击不重复扣 |
| 3 PDF 与效期 | `pdf_fy` 模板、确认自动生成、效期预警页 + Cron（默认关）+ wecom 推送（开关） | PDF 版式人工核对；预警页清单正确；推送开启后到达 |
| 4 集成面 | REST 6 端点、停用→启用全流程、`v0.1.0` | REST 权限矩阵（403/409）；停用→启用无损 |

## 7. 验收标准

- A. 干净库启用 → 从已签发处方开单 → 确认发药 → 库存/批次扣减正确 → 停用 → 启用，单据与库存无损
- B. 并发 20 确认同一发药单：库存只扣一次；并发 20 建单编号唯一连续
- C. FEFO：三批次不同效期，余量跨批次时按效期先出；不足时整单失败且处方仍为已签发
- D. 状态机：草稿处方/已作废处方发药被拒；退回后处方回已签发且可重开单；逆向入库数量与批次一致
- E. 权限：无 `dispense` 用户确认按钮不可见、REST 403；`return` 与 `dispense` 分离
- F. PDF：发药单中文、批次/效期列、签名位人工核对通过
- G. 审计：PHARMACY_* 六类动作可见；效期推送（如开启）内容不含患者医疗数据（仅批次/产品）
- H. `tests/run_all.php` 全过；README/descriptor 版本一致；AGENTS.md 更新

## 8. 开放项（2026-09-22 评审已定）

1. **仓库**：常量默认空 = 发药时选择；单一大药房后可锁定常量
2. **效期预警推送**：默认关；开启需维护者在设置页显式打开（视为对 §5.7 红线的同意）
3. **TCM 饮片扣库存**：与 WM 同规则（有 fk_product 扣，自由文本行不扣）；饮片以克入库，产品建档单位必须与处方行单位一致（g），不换算
4. **少发/拆分发药**：V0.1 整单发药（行数量 = 处方数量）；"允许少发 + 剩余另开单"进 V0.2

## 9. 模块标识

- 目录 `htdocs/custom/pharmacy/`，类 `modPharmacy`，常量 `MAIN_MODULE_PHARMACY`
- 模块 ID **501630**；权限 ID `50163011/21/31/41/51`（read/write/dispense/return/admin）
- `depends = array('modPatient', 'modPrescription', 'modProduct', 'modStock', 'modProductBatch')`（批次依赖 modProductBatch，其自身级联 modProduct/modStock/modExpedition/modFournisseur——已核对 `core/modules/modProductBatch.class.php:74`）；语言 `langs/zh_CN/pharmacy.lang`、`langs/en_US/pharmacy.lang`
- 仓库 `github.com/kongzong/dolibarr-modpharmacy`（推送前由维护者创建）
