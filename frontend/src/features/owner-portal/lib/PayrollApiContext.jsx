import { createContext, useContext } from 'react'
import { createPayrollApi } from './ownerApi'
const PayrollApiContext = createContext(createPayrollApi())
export const PayrollApiProvider = PayrollApiContext.Provider
export const usePayrollApi = () => useContext(PayrollApiContext)
